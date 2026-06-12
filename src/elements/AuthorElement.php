<?php

namespace anvildev\beacon\elements;

use anvildev\beacon\elements\db\AuthorQuery;
use anvildev\beacon\helpers\AuthorPageSettings;
use anvildev\beacon\helpers\BeaconPermissions;
use anvildev\beacon\records\AuthorRecord;
use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * @phpstan-import-type AuthorRouteDefinition from \anvildev\beacon\elements\ElementArrayShapes
 * @phpstan-import-type ElementSourceDefinition from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type ElementTableAttributeMap from \anvildev\beacon\types\ArrayShapes
 * @phpstan-import-type PersonJsonLdNode from \anvildev\beacon\elements\ElementArrayShapes
 * @phpstan-import-type YiiModelRule from \anvildev\beacon\elements\ElementArrayShapes
 */
class AuthorElement extends Element
{
    use BeaconElementPermissionsTrait;

    protected const BEACON_PERMISSION = BeaconPermissions::EDIT_AUTHORS;

    /** @var list<string>|null */
    public ?array $expertise = null;
    /** @var list<string>|null */
    public ?array $credentials = null;
    /** @var list<string>|null */
    public ?array $sameAs = null;
    public ?string $jobTitle = null;
    public ?int $imageAssetId = null;
    public ?string $description = null;
    /** @var list<string>|null */
    public ?array $alumniOf = null;
    /** @var list<string>|null */
    public ?array $affiliation = null;
    /** @var list<string>|null */
    public ?array $worksFor = null;

    public static function displayName(): string
    {
        return Craft::t('beacon', 'elements.author.author');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('beacon', 'elements.author.author.2');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('beacon', 'nav.authors');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('beacon', 'settings.authors.authorPagesUriPrefix.placeholder');
    }

    public static function refHandle(): ?string
    {
        return 'beaconAuthor';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        // Capability declaration: per-instance URI is gated by getUriFormat()
        // returning null when the opt-in setting is off.
        return true;
    }

    public function getUriFormat(): ?string
    {
        if (!AuthorPageSettings::enabled()) {
            return null;
        }
        $prefix = AuthorPageSettings::uriPrefix();
        return ($prefix !== '' ? $prefix . '/' : '') . '{slug}';
    }

    /**
     * Hand off to Craft's templates/render controller so sites can override the
     * default template by placing `templates/beacon/_public/author-profile.twig`
     * in their own project — standard Craft plugin-template fallback applies.
     *
     * @return AuthorRouteDefinition|string|null
     */
    protected function route(): array|string|null
    {
        if ($this->getUriFormat() === null) {
            return null;
        }
        return [
            'templates/render',
            [
                'template' => 'beacon/public/author-profile',
                'variables' => ['author' => $this],
            ],
        ];
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): AuthorQuery
    {
        return new AuthorQuery(static::class);
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('beacon/authors/' . $this->id);
    }

    /** @return YiiModelRule */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = ['jobTitle', 'string', 'max' => 255];
        $rules[] = ['description', 'string', 'max' => 2000];
        $rules[] = ['imageAssetId', 'integer'];
        $rules[] = [['expertise', 'credentials', 'sameAs', 'alumniOf', 'affiliation', 'worksFor'], 'each', 'rule' => ['string', 'max' => 500]];
        $rules[] = [['expertise', 'credentials', 'sameAs', 'alumniOf', 'affiliation', 'worksFor'], 'validateListCount'];
        $rules[] = ['sameAs', 'each', 'rule' => ['url', 'validSchemes' => ['http', 'https']]];
        return $rules;
    }

    /**
     * Caps `expertise`/`credentials`/`sameAs` at 50 entries each so a malicious
     * editor can't bloat the JSON-encoded record column (and the JSON-LD that
     * later mirrors it) with thousands of strings.
     */
    public function validateListCount(string $attribute): void
    {
        $value = $this->$attribute;
        if (is_array($value) && count($value) > 50) {
            $this->addError($attribute, Craft::t('beacon', 'elements.author.may.not.contain.more.than', [
                'attribute' => $attribute,
            ]));
        }
    }

    /**
     * Build the schema.org Person JSON-LD node for this author. Returns null
     * when the author has no name (the one truly-required Person property).
     *
     * Single source of truth for: GEO-provenance `author` array, schema-bundle
     * `author` auto-fill, and any future @id-linked emissions.
     *
     * @return PersonJsonLdNode|null
     */
    public function toPersonNode(): ?array
    {
        $name = trim((string) $this->title);
        if ($name === '') {
            return null;
        }
        $node = ['@id' => $this->resolveSchemaId(), '@type' => 'Person', 'name' => $name];
        $jobTitle = trim($this->jobTitle ?? '');
        if ($jobTitle !== '') {
            $node['jobTitle'] = $jobTitle;
        }
        $description = trim($this->description ?? '');
        if ($description !== '') {
            $node['description'] = $description;
        }
        if (($imageUrl = $this->resolveImageUrl()) !== null) {
            $node['image'] = $imageUrl;
        }
        if (($sameAs = $this->cleanStringList($this->sameAs)) !== []) {
            $node['sameAs'] = $sameAs;
        }
        if (($knowsAbout = $this->cleanStringList($this->expertise)) !== []) {
            $node['knowsAbout'] = $knowsAbout;
        }
        $hasCredential = array_values(array_map(
            static fn(string $c): array => ['@type' => 'EducationalOccupationalCredential', 'name' => $c],
            $this->cleanStringList($this->credentials),
        ));
        if ($hasCredential !== []) {
            $node['hasCredential'] = $hasCredential;
        }
        foreach (['alumniOf', 'affiliation', 'worksFor'] as $prop) {
            if (($vals = $this->cleanStringList($this->$prop)) !== []) {
                $node[$prop] = $vals;
            }
        }

        /** @var PersonJsonLdNode $node */
        return $node;
    }

    /**
     * Stable JSON-LD `@id` so the same author dedupes across nodes and pages:
     * the public profile URL when author pages are enabled and a URL resolves,
     * otherwise a site-independent URN keyed by the element uid (survives slug
     * changes and works even when author pages are off).
     */
    private function resolveSchemaId(): string
    {
        if ($this->getUriFormat() !== null) {
            $url = $this->getUrl();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return 'urn:beacon:author:' . (string) $this->uid;
    }

    /**
     * Trim + drop-empty pass shared by the array-of-strings Person properties.
     *
     * @param list<string>|null $values
     * @return list<string>
     */
    private function cleanStringList(?array $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn($v): string => trim((string) $v), $values),
            static fn(string $v): bool => $v !== '',
        ));
    }

    /**
     * Resolve the configured headshot asset to a public URL. Returns null if no
     * asset is selected, the asset has been deleted, or its URL can't be built
     * (private volume, missing transform, etc.).
     */
    private function resolveImageUrl(): ?string
    {
        if ($this->imageAssetId === null || $this->imageAssetId <= 0) {
            return null;
        }
        $asset = Craft::$app->getAssets()->getAssetById($this->imageAssetId);
        if (!$asset instanceof Asset) {
            return null;
        }
        $url = $asset->getUrl();
        return is_string($url) && $url !== '' ? $url : null;
    }

    public function afterSave(bool $isNew): void
    {
        $record = AuthorRecord::findOne($this->id) ?? new AuthorRecord();
        $record->id = $this->id;
        $record->expertise = $this->expertise;
        $record->credentials = $this->credentials;
        $record->sameAs = $this->sameAs;
        $record->jobTitle = $this->jobTitle;
        $record->imageAssetId = $this->imageAssetId;
        $record->description = $this->description;
        $record->alumniOf = $this->alumniOf;
        $record->affiliation = $this->affiliation;
        $record->worksFor = $this->worksFor;
        $record->save(false);
        parent::afterSave($isNew);
    }

    /** @return list<ElementSourceDefinition> */
    protected static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('beacon', 'elements.author.all.authors'),
                'criteria' => [],
            ],
        ];
    }

    /** @return ElementTableAttributeMap */
    protected static function defineTableAttributes(): array
    {
        return [
            'jobTitle' => ['label' => Craft::t('beacon', 'authors.edit.jobTitle.label')],
            'sameAsCount' => ['label' => Craft::t('beacon', 'elements.author.sameas.links')],
            'lastAttachedEntry' => ['label' => Craft::t('beacon', 'elements.author.last.attached.entry')],
            'dateUpdated' => ['label' => Craft::t('beacon', 'schemas.edit.updated.text')],
        ];
    }

    /** @return list<string> */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['jobTitle', 'sameAsCount', 'lastAttachedEntry', 'dateUpdated'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'jobTitle' => Html::encode((string) ($this->jobTitle ?? '')),
            'sameAsCount' => (string) (is_array($this->sameAs) ? count($this->sameAs) : 0),
            'lastAttachedEntry' => $this->renderLastAttachedEntry(),
            default => parent::attributeHtml($attribute),
        };
    }

    private function renderLastAttachedEntry(): string
    {
        if ($this->id === null) {
            return '';
        }
        $entry = Entry::find()
            ->relatedTo(['targetElement' => $this->id])
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->status(null)
            ->one();
        if (!$entry instanceof Entry) {
            return '<span class="light">—</span>';
        }
        $url = $entry->getCpEditUrl();
        $label = Html::encode((string) $entry->title);
        return is_string($url)
            ? '<a href="' . Html::encode($url) . '">' . $label . '</a>'
            : $label;
    }
}
