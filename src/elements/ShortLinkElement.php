<?php

namespace anvildev\beacon\elements;

use anvildev\beacon\elements\db\ShortLinkQuery;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\helpers\ShortLinkSlug;
use anvildev\beacon\records\ShortLinkRecord;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * @phpstan-import-type ElementSortOption from \anvildev\beacon\elements\ElementArrayShapes
 * @phpstan-import-type ElementSourceDefinition from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type ElementTableAttributeMap from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type YiiModelRule from \anvildev\beacon\elements\ElementArrayShapes
 */
class ShortLinkElement extends Element
{
    use BeaconElementPermissionsTrait;
    use HasPropagationTrait;
    use ValidatesRedirectLinkTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_SHORT_LINKS;

    public ?string $destination = null;
    public int $statusCode = 302;
    public int $clicks = 0;
    public ?string $lastClicked = null;
    public ?DateTime $expiresAt = null;
    public ?string $note = null;

    /** @return list<string> */
    public function datetimeAttributes(): array
    {
        return [...parent::datetimeAttributes(), 'expiresAt'];
    }

    public static function displayName(): string
    {
        return Craft::t('beacon', 'Short link');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('beacon', 'short link');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('beacon', 'Short links');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('beacon', 'short links');
    }

    public static function refHandle(): ?string
    {
        return 'beaconShortLink';
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

    public static function find(): ShortLinkQuery
    {
        return new ShortLinkQuery(static::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('beacon/short-links/' . $this->id);
    }

    /** @return YiiModelRule */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['slug', 'required'];
        $rules[] = ['slug', 'validateSlug'];
        $rules[] = ['destination', 'required'];
        $rules[] = ['destination', 'validateDestination'];
        $rules[] = ['destination', 'string', 'max' => 1000];
        $rules[] = ['statusCode', 'validateStatusCode'];
        $rules[] = ['note', 'string', 'max' => 500];
        return $rules;
    }

    /** The slug is shared across propagated sites, so it must be globally unique. */
    public function validateSlug(string $attribute): void
    {
        $slug = (string) $this->$attribute;
        if (($error = ShortLinkSlug::validate($slug)) !== null) {
            $this->addError($attribute, Craft::t('beacon', $error));
            return;
        }
        $exists = ShortLinkRecord::find()
            ->where(['slug' => $slug])
            ->andWhere(['not', ['id' => (int) ($this->id ?? 0)]])
            ->exists();
        if ($exists) {
            $this->addError($attribute, Craft::t('beacon', 'A short link with that slug already exists.'));
        }
    }

    /**
     * The destination is emitted verbatim as a `Location:` header in the 404
     * listener, so it must pass the same allowlist the redirect importer
     * enforces — relative paths or http(s) only. Without this, a stored
     * `//evil.example` or `javascript:` destination becomes an open-redirect /
     * phishing vector. Mirrors {@see RedirectElement::validateTargetUri()}.
     */
    public function validateDestination(string $attribute): void
    {
        $this->validateRedirectTargetUri($attribute, 'Destination contains invalid line breaks.');
    }

    public function beforeSave(bool $isNew): bool
    {
        // Short links have no separate label — the slug is the identity, so the
        // element title mirrors it (drives the element-index name column).
        $this->title = (string) $this->slug;
        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        // Only the canonical save writes the shared data row; propagated saves
        // to sibling sites must not duplicate it. (See craft-plugin-dev: guard
        // side effects with $element->propagating.)
        if (!$this->propagating) {
            $record = (!$isNew ? ShortLinkRecord::findOne($this->id) : null) ?? new ShortLinkRecord();
            $record->id = (int) $this->id;
            $record->propagationMethod = $this->propagationMethod->value;
            $record->slug = (string) $this->slug;
            $record->destination = (string) $this->destination;
            $record->statusCode = (int) $this->statusCode;
            $record->expiresAt = Db::prepareDateForDb($this->expiresAt);
            $record->note = $this->note ?: null;
            if ($isNew) {
                $record->clicks = 0;
            }
            $record->save(false);
        }
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        if ($this->hardDelete) {
            ShortLinkRecord::findOne($this->id)?->delete();
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
        return [
            [
                'key' => '*',
                'label' => Craft::t('beacon', 'All short links'),
                'criteria' => [],
            ],
        ];
    }

    /** @return ElementTableAttributeMap */
    protected static function defineTableAttributes(): array
    {
        // The title (= slug, set in beforeSave) is the element's name column.
        return [
            'title' => ['label' => Craft::t('beacon', 'Slug')],
            'destination' => ['label' => Craft::t('beacon', 'Destination')],
            'statusCode' => ['label' => Craft::t('beacon', 'Status')],
            'clicks' => ['label' => Craft::t('beacon', 'Clicks')],
            'lastClicked' => ['label' => Craft::t('beacon', 'Last clicked')],
            'dateUpdated' => ['label' => Craft::t('beacon', 'Updated')],
        ];
    }

    /** @return list<string> */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['destination', 'statusCode', 'clicks', 'lastClicked'];
    }

    /** @return list<ElementSortOption> */
    protected static function defineSortOptions(): array
    {
        return [
            ['label' => Craft::t('beacon', 'Slug'), 'orderBy' => 'beacon_short_links.slug', 'attribute' => 'title'],
            ['label' => Craft::t('beacon', 'Clicks'), 'orderBy' => 'beacon_short_links.clicks', 'attribute' => 'clicks'],
            ['label' => Craft::t('beacon', 'Last clicked'), 'orderBy' => 'beacon_short_links.lastClicked', 'attribute' => 'lastClicked'],
            ['label' => Craft::t('app', 'Date Updated'), 'orderBy' => 'elements.dateUpdated', 'attribute' => 'dateUpdated'],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'destination' => $this->renderDestination(),
            'statusCode' => (string) $this->statusCode,
            'clicks' => (string) $this->clicks,
            default => parent::attributeHtml($attribute),
        };
    }

    private function renderDestination(): string
    {
        $dest = (string) $this->destination;
        if ($dest === '') {
            return Html::tag('span', '—', ['class' => 'light']);
        }
        $label = mb_strlen($dest) > 60 ? mb_substr($dest, 0, 57) . '…' : $dest;
        return Html::a(Html::encode($label), $dest, ['rel' => 'noopener', 'target' => '_blank']);
    }
}
