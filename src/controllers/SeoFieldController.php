<?php

namespace anvildev\beacon\controllers;

use anvildev\beacon\helpers\Assets;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\Plugin;
use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * AJAX endpoints used by the Beacon SEO field's CP UI.
 *
 * Currently exposes a single action: `suggest-mapping`, which the field's
 * "Suggest" button calls to fill in a JSON-LD property mapping for a given
 * (entry, schema type) pair.
 *
 * Routed automatically as `actions/beacon/seo-field/suggest-mapping` via the
 * plugin's `controllerNamespace`.
 */
class SeoFieldController extends Controller
{
    use PostJsonTrait;

    protected array|bool|int $allowAnonymous = false;

    /**
     * Suggests a JSON-LD property mapping for an (entry, schema type) pair.
     *
     * @throws \yii\web\BadRequestHttpException when the schema type is empty or unknown
     * @throws \yii\web\NotFoundHttpException when the entry can't be found
     * @throws \yii\web\ForbiddenHttpException when the current user can't view the entry
     */
    public function actionSuggestMapping(): Response
    {
        $this->requirePostJson();
        $request = Http::request();
        $suggester = Plugin::$plugin->schemaSuggester;

        $entryId = (int) $request->getRequiredBodyParam('entryId');
        $type = trim((string) $request->getRequiredBodyParam('type'));
        if ($type === '' || !$suggester->knowsType($type)) {
            throw new BadRequestHttpException(Craft::t('beacon', 'Unknown schema type: {type}', ['type' => $type]));
        }

        $entry = $this->findEntryForField($entryId);
        $this->requireCanView($entry);

        return $this->asJson(['mapping' => $suggester->suggest($entry, $type), 'type' => $type]);
    }

    /**
     * @throws NotFoundHttpException when the entry can't be found
     */
    private function findEntryForField(int $entryId, ?int $siteId = null): Entry
    {
        $entry = Entry::find()
            ->id($entryId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->siteId($siteId ?? '*')
            ->one();
        if (!$entry instanceof Entry) {
            throw new NotFoundHttpException(Craft::t('beacon', 'Entry {id} not found', ['id' => $entryId]));
        }
        return $entry;
    }

    /**
     * @throws ForbiddenHttpException when the current user can't view $entry
     *   (or isn't logged in at all).
     */
    private function requireCanView(Entry $entry): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !Craft::$app->getElements()->canView($entry, $user)) {
            throw new ForbiddenHttpException();
        }
    }

    /**
     * Re-resolves the SEO meta fallback for the current (unsaved) form state
     * so the field can mirror Beacon's runtime resolution as the editor types.
     *
     * The client posts whatever the user has entered so far — the unsaved
     * entry title plus the in-progress Beacon SEO field payload — and we run
     * the same MetaResolver path that the front-end renderer uses. The result
     * lets the field's placeholders show the *actual* resolved title /
     * description / image instead of static labels like "Global default".
     *
     * @throws \yii\web\NotFoundHttpException when the entry can't be found
     * @throws \yii\web\ForbiddenHttpException when the current user can't view the entry
     */
    public function actionResolveFallback(): Response
    {
        $this->requirePostJson();
        $request = Http::request();
        $plugin = Plugin::$plugin;

        $entryId = (int) $request->getRequiredBodyParam('entryId');
        $siteId = (int) ($request->getBodyParam('siteId') ?: 0) ?: null;
        $entryTitle = mb_substr((string) ($request->getBodyParam('entryTitle') ?? ''), 0, 500);
        $fieldValue = $request->getBodyParam('fieldValue');
        if (!is_array($fieldValue)) {
            $fieldValue = [];
        }

        $entry = $this->findEntryForField($entryId, $siteId);
        $this->requireCanView($entry);

        $typeHandle = $entry->getType()->handle ?? '';
        $bundles = $typeHandle !== ''
            ? array_map(static fn($r) => $r->schemaType, $plugin->bundles->getSchemasForEntryType($typeHandle))
            : [];

        $meta = $plugin->metaResolver->resolve(
            $fieldValue,
            $entryTitle !== '' ? $entryTitle : (string) $entry->title,
            $entry->getSite()->name,
            $plugin->settings->getGeoDefaults(),
            $entry->getUrl(),
            $entry,
            $bundles,
        );

        return $this->asJson([
            'title' => $meta->title,
            'description' => $meta->description,
            'ogImage' => (string) ($meta->openGraph['image'] ?? ''),
            'ogImageThumb' => $this->resolveOgImageThumb($fieldValue, $meta),
            'sourceMap' => $meta->sourceMap,
        ]);
    }

    /**
     * If the resolver landed on an image, pick a CP-friendly thumb URL. We
     * prefer a small transform on the originating asset when one is in scope;
     * otherwise we return the resolver's URL as-is.
     *
     * @param array<string,mixed> $fieldValue
     */
    private function resolveOgImageThumb(array $fieldValue, SeoMeta $meta): string
    {
        $url = (string) ($meta->openGraph['image'] ?? '');
        if ($url === '') {
            return '';
        }
        $assetId = $fieldValue['ogImageId'] ?? null;
        if (!is_numeric($assetId) || (int) $assetId <= 0) {
            return $url;
        }
        $asset = Assets::findById((int) $assetId);
        if ($asset === null) {
            return $url;
        }
        return (string) ($asset->getUrl(['width' => 320, 'height' => 168, 'mode' => 'crop']) ?? $url);
    }
}
