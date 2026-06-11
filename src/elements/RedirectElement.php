<?php

namespace anvildev\beacon\elements;

use anvildev\beacon\elements\db\RedirectQuery;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\RedirectStructure;
use anvildev\beacon\helpers\SafeRegex;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\records\RedirectRecord;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * @phpstan-import-type ElementSourceDefinition from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type ElementTableAttributeMap from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type YiiModelRule from \anvildev\beacon\elements\ElementArrayShapes
 *
 * A redirect rule as a localized Craft element.
 *
 * Propagation maps to the old per-site model: {@see HasPropagationTrait}'s
 * `None` = this site only, `All` = every site. Link data is shared across the
 * sites the rule propagates to. Resolution happens in the 404 listener via
 * {@see \anvildev\beacon\services\RedirectService::findRedirect()}, not element
 * routing, so the element declares no URIs. Precedence is editor-controlled by
 * drag-reordering in the native element index, backed by a single-level Craft
 * Structure; the `sortOrder` column (which the matcher orders by) is re-synced
 * from the structure on every move.
 */
class RedirectElement extends Element
{
    use BeaconElementPermissionsTrait;
    use HasPropagationTrait;
    use ValidatesRedirectLinkTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_REDIRECTS;

    public ?string $sourceUri = null;
    public ?string $targetUri = null;
    public int $statusCode = 301;
    public string $type = 'exact';
    public string $queryStringMode = 'ignore';
    public int $hits = 0;
    public ?string $lastHit = null;
    public ?string $note = null;
    public string $source = 'manual';
    public int $sortOrder = 0;
    /** Source entry this redirect is attached to (via BeaconRedirectSourcesField), if any. */
    public ?int $attachedElementId = null;
    public ?int $attachedElementSiteId = null;

    public static function displayName(): string
    {
        return Craft::t('beacon', 'Redirect');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('beacon', 'redirect');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('beacon', 'Redirects');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('beacon', 'redirects');
    }

    public static function refHandle(): ?string
    {
        return 'beaconRedirect';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): RedirectQuery
    {
        return new RedirectQuery(static::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('beacon/redirects/' . $this->id);
    }

    /** @return YiiModelRule */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sourceUri', 'targetUri'], 'required'];
        $rules[] = ['sourceUri', 'string', 'max' => 255];
        $rules[] = ['targetUri', 'string', 'max' => 500];
        $rules[] = ['note', 'string', 'max' => 500];
        $rules[] = ['statusCode', 'validateStatusCode'];
        $rules[] = ['type', 'validateType'];
        $rules[] = ['targetUri', 'validateTargetUri'];
        $rules[] = ['sourceUri', 'validateSourceUri'];
        return $rules;
    }

    public function validateType(string $attribute): void
    {
        if (RedirectType::tryFrom((string) $this->$attribute) === null) {
            $this->addError($attribute, Craft::t('beacon', 'Invalid type.'));
        }
    }

    public function validateTargetUri(string $attribute): void
    {
        $this->validateRedirectTargetUri($attribute, 'Target URI contains invalid line breaks.');
    }

    public function validateSourceUri(string $attribute): void
    {
        $value = (string) $this->$attribute;
        if (Strings::containsLineBreaks($value)) {
            $this->addError($attribute, Craft::t('beacon', 'Source URI contains invalid line breaks.'));
            return;
        }
        if ($this->type === RedirectType::Regex->value && ($err = SafeRegex::validate($value)) !== null) {
            $this->addError($attribute, Craft::t('beacon', $err));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        $this->sourceUri = $this->normalizeSourceUri();
        // Source URI drives the element label / index name column.
        $this->title = (string) $this->sourceUri;
        return parent::beforeSave($isNew);
    }

    /**
     * Exact/glob sources are path-relative and matched against the incoming
     * path (which always has a leading slash), so normalise `hallo` → `/hallo`.
     * Regex patterns and absolute URLs are left untouched.
     */
    private function normalizeSourceUri(): string
    {
        $src = trim((string) $this->sourceUri);
        if ($src === '' || $this->type === RedirectType::Regex->value) {
            return $src;
        }
        if (str_starts_with($src, '/') || preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        return '/' . $src;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = (!$isNew ? RedirectRecord::findOne($this->id) : null) ?? new RedirectRecord();
            $record->id = (int) $this->id;
            $record->propagationMethod = $this->propagationMethod->value;
            $record->sourceUri = (string) $this->sourceUri;
            $record->targetUri = (string) $this->targetUri;
            $record->statusCode = (int) $this->statusCode;
            $record->type = $this->type;
            $record->queryStringMode = $this->queryStringMode;
            $record->note = $this->note ?: null;
            $record->source = $this->source;
            $record->sortOrder = $this->sortOrder;
            $record->elementId = $this->attachedElementId;
            $record->elementSiteId = $this->attachedElementSiteId;
            if ($isNew) {
                $record->hits = 0;
            }
            $record->save(false);

            if ($isNew) {
                $structureId = RedirectStructure::ensureExists();
                Craft::$app->getStructures()->appendToRoot($structureId, $this);
            }
        }
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        if ($this->hardDelete) {
            RedirectRecord::findOne($this->id)?->delete();
        }
        parent::afterDelete();
    }

    /** @return list<class-string<\craft\base\ElementActionInterface>> */
    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    /** @return list<ElementSourceDefinition> */
    protected static function defineSources(?string $context = null): array
    {
        $source = [
            'key' => '*',
            'label' => Craft::t('beacon', 'All redirects'),
            'criteria' => [],
        ];

        $structureId = RedirectStructure::structureId();
        if ($structureId !== null) {
            $source['structureId'] = $structureId;
            $source['structureEditable'] = true;
        }

        return [$source];
    }

    /** @return ElementTableAttributeMap */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('beacon', 'Source')],
            'targetUri' => ['label' => Craft::t('beacon', 'Target')],
            'statusCode' => ['label' => Craft::t('beacon', 'Status')],
            'type' => ['label' => Craft::t('beacon', 'Type')],
            'hits' => ['label' => Craft::t('beacon', 'Hits')],
            'lastHit' => ['label' => Craft::t('beacon', 'Last hit')],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'targetUri' => Html::tag('code', Html::encode((string) $this->targetUri)),
            'statusCode' => (string) $this->statusCode,
            'type' => Html::encode($this->type),
            'hits' => (string) $this->hits,
            default => parent::attributeHtml($attribute),
        };
    }
}
