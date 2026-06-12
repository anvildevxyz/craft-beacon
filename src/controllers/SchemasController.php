<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\helpers\Json as JsonHelper;
use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\models\SchemaBundle;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\SchemaRecord;
use anvildev\beacon\schemas\SchemaPropertyRegistry;
use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SchemasController extends Controller
{
    use BeaconCpPermissionTrait;
    use JsonToggleTrait;
    use RetainSubmittedFormTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SCHEMAS;

    /**
     * Lists the configured schema mappings grouped by entry type.
     */
    public function actionIndex(): Response
    {
        $grouped = [];
        foreach (Plugin::$plugin->schema->list() as $r) {
            $grouped[$r->entryTypeHandle][] = $r;
        }
        return $this->renderTemplate('beacon/schemas/index', ['grouped' => $grouped]);
    }

    /**
     * Renders the schema mapping edit form (or a blank one for a new schema).
     *
     * @throws \yii\web\NotFoundHttpException when $schemaId is given but no matching schema exists
     */
    public function actionEdit(?int $schemaId = null, ?SchemaRecord $schema = null): Response
    {
        $schemas = Plugin::$plugin->schema;
        // $schema may be injected by actionSave() on a failed save (via
        // setRouteParams) so the form re-renders carrying the submitted input.
        if ($schema === null) {
            if ($schemaId !== null) {
                $schema = $schemas->findById($schemaId);
                if (!$schema) {
                    throw new NotFoundHttpException();
                }
            } else {
                $schema = $schemas->newRecord();
                $schema->schemaType = 'Article';
                $schema->enabled = true;
                $schema->sortOrder = 0;
            }
        }

        return $this->renderTemplate('beacon/schemas/_edit', $this->editVariables($schema));
    }

    /**
     * Creates or updates a schema mapping from the posted form (validating the
     * mapping JSON), clears the bundle cache, and redirects on success.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Http::request();
        $session = Craft::$app->getSession();
        $plugin = Plugin::$plugin;
        $schemas = $plugin->schema;

        $id = $request->getBodyParam('schemaId');
        $record = ($id ? $schemas->findById((int) $id) : null) ?? $schemas->newRecord();

        $record->entryTypeHandle = trim((string) $request->getBodyParam('entryTypeHandle'));
        $record->schemaType = trim((string) $request->getBodyParam('schemaType', 'Article'));
        $record->enabled = (bool) $request->getBodyParam('enabled', true);

        // Keep the raw submission on the record so a validation failure re-renders
        // the form with the user's input intact instead of silently discarding it.
        $record->mapping = $mappingJson = trim((string) $request->getBodyParam('mapping', '{}')) ?: '{}';

        if (strlen($mappingJson) > 65535) {
            $session->setError(Craft::t('beacon', 'flash.schemas.mapping.json.exceeds.64.kib'));
            return $this->retainOnError($record);
        }
        $decoded = JsonHelper::decodeObject($mappingJson);
        if ($decoded === null) {
            $session->setError(Craft::t('beacon', 'flash.schemas.mapping.must.valid.json.object'));
            return $this->retainOnError($record);
        }

        if ($record->entryTypeHandle === '' || $record->schemaType === '') {
            $session->setError(Craft::t('beacon', 'flash.schemas.entry.type.schema.type.required'));
            return $this->retainOnError($record);
        }
        $now = Db::now();
        if ($record->isNewRecord) {
            $record->sortOrder = $schemas->nextSortOrderForEntryType($record->entryTypeHandle);
            $record->dateCreated = $now;
        }
        $record->dateUpdated = $now;

        if (!$record->save()) {
            $session->setError(Craft::t('beacon', 'flash.schemas.couldnt.save'));
            return $this->retainOnError($record);
        }

        $plugin->bundles->clearCache();
        $session->setNotice(Craft::t('beacon', 'flash.schemas.schema.saved'));
        return $this->redirectToPostedUrl($record);
    }

    /**
     * Deletes the schema mapping identified by the posted `schemaId`, clears the
     * bundle cache, and returns a JSON result.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     * @throws \yii\web\ForbiddenHttpException when the user is not an admin
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $plugin = Plugin::$plugin;
        $record = $plugin->schema->findById((int) Http::request()->getRequiredBodyParam('schemaId'));
        if ($record) {
            $record->delete();
            $plugin->bundles->clearCache();
        }
        return $this->asJson(['success' => true]);
    }

    /**
     * Applies the posted schema ordering, clears the bundle cache, and returns a
     * JSON result.
     *
     * @throws \yii\web\BadRequestHttpException when the request is not a POST
     * @throws \yii\web\ForbiddenHttpException when the user is not an admin
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $plugin = Plugin::$plugin;
        /** @var list<int> $ids */
        $ids = Http::request()->getRequiredBodyParam('ids');
        $plugin->schema->applyOrder(array_map(intval(...), $ids));
        $plugin->bundles->clearCache();
        return $this->asJson(['success' => true]);
    }

    /**
     * Toggles a schema's enabled flag, returning JSON for the inline switch.
     *
     * @throws \yii\web\NotFoundHttpException when no schema matches the posted id
     */
    public function actionToggle(): Response
    {
        $plugin = Plugin::$plugin;

        return $this->toggleEnabled(
            'schemaId',
            static fn(int $id, bool $on) => $plugin->schema->setEnabled($id, $on),
            afterSuccess: static fn() => $plugin->bundles->clearCache(),
        );
    }

    /**
     * Suggests a JSON-LD property mapping for the chosen schema type, used by the
     * builder's "Suggest mappings" button. Prefers a real sample entry of the
     * given entry type (so it can detect asset/date/summary field handles) and
     * falls back to registry-hint-only tokens when no entry exists yet.
     *
     * @throws \yii\web\BadRequestHttpException when the schema type is empty or unknown
     */
    public function actionSuggest(): Response
    {
        $this->requirePostJson();
        $request = Http::request();
        $suggester = Plugin::$plugin->schemaSuggester;

        $entryTypeHandle = trim((string) $request->getBodyParam('entryTypeHandle', ''));
        $type = trim((string) $request->getRequiredBodyParam('schemaType'));
        if ($type === '' || !$suggester->knowsType($type)) {
            throw new BadRequestHttpException(Craft::t('beacon', 'flash.schemas.unknown.schema.type', ['type' => $type]));
        }

        $entry = $entryTypeHandle !== '' ? $this->sampleEntry($entryTypeHandle) : null;
        $mapping = $entry !== null
            ? $suggester->suggest($entry, $type)
            : $suggester->suggestForType($type);

        return $this->asJson(['mapping' => $mapping]);
    }

    /**
     * Renders the JSON-LD a mapping would produce against a sample entry of the
     * chosen entry type, so editors can verify the output before saving. Returns
     * a soft `error`/`empty` payload rather than throwing, so the preview panel
     * can show friendly guidance while the form is still incomplete.
     */
    public function actionPreview(): Response
    {
        $this->requirePostJson();
        $request = Http::request();
        $plugin = Plugin::$plugin;

        $type = trim((string) $request->getBodyParam('schemaType', ''));
        if ($type === '') {
            return $this->asJson(['error' => Craft::t('beacon', 'flash.schemas.choose.schema.type.first')]);
        }

        $decoded = JsonHelper::decodeObject(trim((string) $request->getBodyParam('mapping', '{}')) ?: '{}');
        if ($decoded === null) {
            return $this->asJson(['error' => Craft::t('beacon', 'flash.schemas.mapping.must.valid.json.object')]);
        }
        /** @var array<string,string> $mapping */
        $mapping = array_filter($decoded, static fn($v): bool => is_string($v));

        $entryTypeHandle = trim((string) $request->getBodyParam('entryTypeHandle', ''));
        $entry = $entryTypeHandle !== '' ? $this->sampleEntry($entryTypeHandle) : null;
        $fieldValue = $entry !== null ? (SeoFieldReader::readValueFor($entry) ?? []) : [];

        $context = $plugin->schemaContext->build($entry, $fieldValue);
        $rendered = $plugin->schema->render(new SchemaBundle(), [['type' => $type, 'mapping' => $mapping]], $context);
        $node = $rendered[0] ?? null;

        return $this->asJson([
            'jsonld' => $node !== null && $node !== []
                ? Json::encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'hasSample' => $entry !== null,
            'sampleTitle' => $entry?->title,
        ]);
    }

    /**
     * Template variables shared by {@see actionEdit()} and the post-failure
     * re-render in {@see retainOnError()}.
     *
     * @return array<string,mixed>
     */
    private function editVariables(SchemaRecord $record): array
    {
        $schemas = Plugin::$plugin->schema;
        return [
            'schema' => $record,
            'entryTypes' => $this->collectEntryTypes(),
            'schemaTypes' => $schemas->registeredTypes(),
            'schemaProperties' => SchemaPropertyRegistry::all(),
            'suggestUrl' => 'beacon/schemas/suggest',
            'previewUrl' => 'beacon/schemas/preview',
        ];
    }

    /**
     * Re-renders the edit form with the submitted (invalid) record retained, so
     * the user never loses their input on a validation failure.
     */
    private function retainOnError(SchemaRecord $record): null
    {
        return $this->retainSubmittedForm(['schema' => $record]);
    }

    private function sampleEntry(string $entryTypeHandle): ?Entry
    {
        $entry = Entry::find()
            ->type($entryTypeHandle)
            ->status(null)
            ->siteId('*')
            ->one();
        if (!$entry instanceof Entry) {
            return null;
        }
        $user = Craft::$app->getUser()->getIdentity();
        if ($user !== null && !Craft::$app->getElements()->canView($entry, $user)) {
            return null;
        }
        return $entry;
    }

    /**
     * @return array<string,string>
     */
    private function collectEntryTypes(): array
    {
        $types = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            $types[$type->handle] = $type->name . ' (' . $type->handle . ')';
        }
        ksort($types);
        return $types;
    }
}
