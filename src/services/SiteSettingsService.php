<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\helpers\Json;
use anvildev\beacon\helpers\Strings;
use anvildev\beacon\models\AdsSettings;
use anvildev\beacon\models\BreadcrumbSettings;
use anvildev\beacon\models\HumansSettings;
use anvildev\beacon\models\LlmsSettings;
use anvildev\beacon\models\RobotsSettings;
use anvildev\beacon\models\SitemapSettings;
use anvildev\beacon\models\WebmasterSettings;
use anvildev\beacon\records\AdsSettingsRecord;
use anvildev\beacon\records\HumansSettingsRecord;
use anvildev\beacon\records\LlmsSettingsRecord;
use anvildev\beacon\records\RobotsSettingsRecord;
use anvildev\beacon\records\SitemapSettingsRecord;
use anvildev\beacon\records\WebmasterSettingsRecord;
use craft\base\Element;
use craft\elements\Entry;
use yii\base\Component;

/**
 * @phpstan-import-type SectionSitemapOverride from \anvildev\beacon\models\SitemapSettings
 * @phpstan-import-type UserAgentRule from \anvildev\beacon\models\RobotsSettings
 */
class SiteSettingsService extends Component
{
    /**
     * Per-request memoization keyed by `"<kind>:<siteId>"`. Collapses the
     * `findOne` GraphQL queries would otherwise run per entry per kind down to
     * one per (kind, siteId) per request. Save paths invalidate the relevant slot.
     *
     * @var array<string, AdsSettings|BreadcrumbSettings|HumansSettings|LlmsSettings|RobotsSettings|SitemapSettings|WebmasterSettings>
     */
    private array $cache = [];

    private function cacheKey(string $kind, int $siteId): string
    {
        return $kind . ':' . $siteId;
    }

    private function invalidate(string $kind, int $siteId): void
    {
        unset($this->cache[$this->cacheKey($kind, $siteId)]);
    }

    /**
     * Returns the site's sitemap settings, seeding a default DB row on first access.
     */
    public function getSitemap(int $siteId): SitemapSettings
    {
        $key = $this->cacheKey('sitemap', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof SitemapSettings);
            return $cached;
        }
        $record = SitemapSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedSitemap($siteId);

        $sectionJson = (is_string($record->sectionSitemap) && $record->sectionSitemap !== '') ? $record->sectionSitemap : '{}';
        $frontMatterJson = (is_string($record->geoMarkdownFrontMatter) && $record->geoMarkdownFrontMatter !== '') ? $record->geoMarkdownFrontMatter : '{}';
        $newsSectionsJson = match (true) {
            is_string($record->newsSections) => $record->newsSections,
            is_array($record->newsSections) => Json::encode($record->newsSections),
            default => '',
        };

        return $this->cache[$key] = new SitemapSettings(
            siteId: $siteId,
            sections: Json::decodeStringList($record->sections),
            excludeSections: Json::decodeStringList($record->excludeSections),
            priority: (float) $record->priority,
            changefreq: (string) $record->changefreq,
            newsSections: Json::decodeStringList($newsSectionsJson),
            sectionSitemap: $this->decodeSectionSitemap($sectionJson),
            geoMarkdownFrontMatter: $this->decodeGeoMarkdownFrontMatter($frontMatterJson),
        );
    }

    /**
     * Returns the site's llms.txt settings, seeding a default DB row on first access.
     */
    public function getLlms(int $siteId): LlmsSettings
    {
        $key = $this->cacheKey('llms', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof LlmsSettings);
            return $cached;
        }
        $record = LlmsSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedLlms($siteId);

        return $this->cache[$key] = new LlmsSettings(
            siteId: $siteId,
            enabled: (bool) $record->enabled,
            summary: $record->summary,
            siteNameOverride: $record->siteNameOverride,
            sections: Json::decodeStringList($record->sections),
            policyUrl: Strings::trimToNull($record->policyUrl),
            licenseUrl: Strings::trimToNull($record->licenseUrl),
            contactEmail: Strings::trimToNull($record->contactEmail),
            preferredAttribution: Strings::trimToNull($record->preferredAttribution),
            fullBody: Strings::trimToNull($record->fullBody),
            llmsFullTokenBudget: $record->llmsFullTokenBudget !== null ? (int) $record->llmsFullTokenBudget : null,
        );
    }

    /**
     * Returns the site's humans.txt settings, seeding a default DB row on first access.
     */
    public function getHumans(int $siteId): HumansSettings
    {
        $key = $this->cacheKey('humans', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof HumansSettings);
            return $cached;
        }
        $record = HumansSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedHumans($siteId);

        return $this->cache[$key] = new HumansSettings(
            siteId: $siteId,
            enabled: (bool) $record->enabled,
            body: $record->body,
        );
    }

    /**
     * Auto-resolves the breadcrumb settings for a site:
     *
     *  - `enabled`: defaults to `true`. Override globally by setting
     *    `breadcrumbsEnabled => false` in `config/beacon.php`.
     *  - `homeLabel`: defaults to the title of the entry whose URI is
     *    `__home__` on this site (Craft's home-page convention). When the
     *    site has no resolvable home entry, falls back to `'Home'`. A
     *    `config/beacon.php` `breadcrumbsHomeLabel` map (siteHandle => label)
     *    overrides this on a per-site basis for translation or rebranding.
     */
    public function getBreadcrumbs(int $siteId): BreadcrumbSettings
    {
        $key = $this->cacheKey('breadcrumbs', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof BreadcrumbSettings);
            return $cached;
        }

        $config = $this->readBeaconConfig();
        $enabled = array_key_exists('breadcrumbsEnabled', $config) ? (bool) $config['breadcrumbsEnabled'] : true;

        $configuredLabel = null;
        $labelMap = is_array($config['breadcrumbsHomeLabel'] ?? null) ? $config['breadcrumbsHomeLabel'] : null;
        if ($labelMap !== null && class_exists(\Craft::class) && \Craft::$app !== null) {
            $handle = \Craft::$app->getSites()->getSiteById($siteId)?->handle;
            if (is_string($handle) && isset($labelMap[$handle]) && is_string($labelMap[$handle])) {
                $configuredLabel = trim($labelMap[$handle]) ?: null;
            }
        }

        $homeLabel = $configuredLabel ?? $this->resolveHomeEntryTitle($siteId) ?? 'Home';

        return $this->cache[$key] = new BreadcrumbSettings(
            siteId: $siteId,
            enabled: $enabled,
            homeLabel: $homeLabel,
        );
    }

    /**
     * Reads `config/beacon.php` once (the Craft Config service is itself
     * cached). Returns an empty array if the file doesn't exist.
     *
     * @return array<string,mixed>
     */
    private function readBeaconConfig(): array
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return [];
        }
        $cfg = \Craft::$app->getConfig()->getConfigFromFile('beacon');
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Finds the site's home entry (URI = Craft's `__home__` sentinel) and
     * returns its title. Status is not constrained so a disabled home entry
     * still feeds the label — the alternative would be no label.
     */
    private function resolveHomeEntryTitle(int $siteId): ?string
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return null;
        }
        $entry = Entry::find()
            ->siteId($siteId)
            ->uri(Element::HOMEPAGE_URI)
            ->status(null)
            ->one();
        $title = $entry instanceof Entry ? $entry->title : null;
        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * Returns the site's ads.txt settings, seeding a default DB row on first access.
     */
    public function getAds(int $siteId): AdsSettings
    {
        $key = $this->cacheKey('ads', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof AdsSettings);
            return $cached;
        }
        $record = AdsSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedAds($siteId);

        return $this->cache[$key] = new AdsSettings(
            siteId: $siteId,
            enabled: (bool) $record->enabled,
            assetId: $record->assetId !== null ? (int) $record->assetId : null,
            body: $record->body,
        );
    }

    /**
     * Returns the site's robots.txt settings, seeding a default DB row on first access.
     */
    public function getRobots(int $siteId): RobotsSettings
    {
        $key = $this->cacheKey('robots', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof RobotsSettings);
            return $cached;
        }
        $record = RobotsSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedRobots($siteId);

        return $this->cache[$key] = new RobotsSettings(
            siteId: $siteId,
            sitemapUrl: (string) $record->sitemapUrl,
            userAgentRules: $this->parseUserAgentRules($record->userAgentRules),
        );
    }

    /**
     * Returns the site's webmaster settings, seeding a default DB row on first
     * access. The IndexNow key may be overridden per-site via `config/beacon.php`
     * (see {@see self::resolveIndexNowKey()}).
     */
    public function getWebmaster(int $siteId): WebmasterSettings
    {
        $key = $this->cacheKey('webmaster', $siteId);
        if (isset($this->cache[$key])) {
            $cached = $this->cache[$key];
            assert($cached instanceof WebmasterSettings);
            return $cached;
        }
        $record = WebmasterSettingsRecord::findOne(['siteId' => $siteId])
            ?? $this->seedWebmaster($siteId);

        return $this->cache[$key] = new WebmasterSettings(
            siteId: $siteId,
            indexNowKey: $this->resolveIndexNowKey($siteId, Strings::trimToNull($record->indexNowKey)),
        );
    }

    /**
     * Lets project admins keep IndexNow keys in `config/beacon.php` under
     * `indexNowKeys` — a map of `siteHandle => key` — so they don't end up
     * baked into the DB across environments. The config map overrides the
     * per-site DB key, which remains the default store.
     */
    private function resolveIndexNowKey(int $siteId, ?string $dbValue): ?string
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return $dbValue;
        }
        $handle = \Craft::$app->getSites()->getSiteById($siteId)?->handle;
        if (!is_string($handle) || $handle === '') {
            return $dbValue;
        }
        $config = \Craft::$app->getConfig()->getConfigFromFile('beacon');
        $map = is_array($config) && is_array($config['indexNowKeys'] ?? null) ? $config['indexNowKeys'] : null;
        if ($map === null) {
            return $dbValue;
        }
        $trimmed = is_string($map[$handle] ?? null) ? trim($map[$handle]) : '';
        return $trimmed !== '' ? $trimmed : $dbValue;
    }

    /**
     * Breadcrumbs are derived from `config/beacon.php` plus the site's home
     * entry — there's no DB row. This method overrides the in-memory cache
     * so tests (and runtime callers) can temporarily flip the resolved state
     * without rewriting the config file.
     */
    public function saveBreadcrumbs(BreadcrumbSettings $s): void
    {
        $this->cache[$this->cacheKey('breadcrumbs', $s->siteId)] = $s;
    }

    /**
     * Persists the site's sitemap settings and invalidates the cached slot.
     */
    public function saveSitemap(SitemapSettings $s): void
    {
        $record = SitemapSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedSitemap($s->siteId);
        $record->sections = Json::encode($s->sections);
        $record->excludeSections = Json::encode($s->excludeSections);
        $record->priority = (string) $s->priority;
        $record->changefreq = $s->changefreq;
        $record->newsSections = Json::encode($s->newsSections);
        $encodedSection = Json::encode($s->sectionSitemap, JSON_UNESCAPED_UNICODE);
        $record->sectionSitemap = ($encodedSection !== false && $encodedSection !== '') ? $encodedSection : '{}';
        $encodedFrontMatter = Json::encode($s->geoMarkdownFrontMatter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $record->geoMarkdownFrontMatter = ($encodedFrontMatter !== false && $encodedFrontMatter !== '') ? $encodedFrontMatter : '{}';
        $this->touchAndSave($record);
        $this->invalidate('sitemap', $s->siteId);
    }

    /**
     * Persists the site's llms.txt settings and invalidates the cached slot.
     */
    public function saveLlms(LlmsSettings $s): void
    {
        $record = LlmsSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedLlms($s->siteId);
        $record->enabled = $s->enabled;
        $record->summary = $s->summary;
        $record->siteNameOverride = $s->siteNameOverride;
        $record->sections = Json::encode($s->sections);
        $record->policyUrl = $s->policyUrl;
        $record->licenseUrl = $s->licenseUrl;
        $record->contactEmail = $s->contactEmail;
        $record->preferredAttribution = $s->preferredAttribution;
        $record->fullBody = $s->fullBody;
        $record->llmsFullTokenBudget = $s->llmsFullTokenBudget;
        $this->touchAndSave($record);
        $this->invalidate('llms', $s->siteId);
    }

    /**
     * Persists the site's robots.txt settings and invalidates the cached slot.
     */
    public function saveRobots(RobotsSettings $s): void
    {
        $record = RobotsSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedRobots($s->siteId);
        $record->sitemapUrl = $s->sitemapUrl;
        $record->userAgentRules = Json::encode($s->userAgentRules);
        $this->touchAndSave($record);
        $this->invalidate('robots', $s->siteId);
    }

    /**
     * Persists the site's humans.txt settings and invalidates the cached slot.
     */
    public function saveHumans(HumansSettings $s): void
    {
        $record = HumansSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedHumans($s->siteId);
        $record->enabled = $s->enabled;
        $record->body = $s->body;
        $this->touchAndSave($record);
        $this->invalidate('humans', $s->siteId);
    }

    /**
     * Persists the site's ads.txt settings and invalidates the cached slot.
     */
    public function saveAds(AdsSettings $s): void
    {
        $record = AdsSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedAds($s->siteId);
        $record->enabled = $s->enabled;
        $record->assetId = $s->assetId;
        $record->body = $s->body;
        $this->touchAndSave($record);
        $this->invalidate('ads', $s->siteId);
    }

    /**
     * Persists the site's webmaster settings and invalidates the cached slot.
     */
    public function saveWebmaster(WebmasterSettings $s): void
    {
        $record = WebmasterSettingsRecord::findOne(['siteId' => $s->siteId])
            ?? $this->seedWebmaster($s->siteId);
        $record->indexNowKey = Strings::trimToNull($s->indexNowKey);
        $this->touchAndSave($record);
        $this->invalidate('webmaster', $s->siteId);
    }

    /**
     * Creates default DB rows for every per-site settings kind (sitemap, llms,
     * robots, humans, ads, webmaster). Called when a new site is added so each
     * site has a baseline row to edit.
     */
    public function seedDefaultsForSite(int $siteId): void
    {
        \Craft::$app->getDb()->transaction(function() use ($siteId): void {
            $this->seedSitemap($siteId);
            $this->seedLlms($siteId);
            $this->seedRobots($siteId);
            $this->seedHumans($siteId);
            $this->seedAds($siteId);
            $this->seedWebmaster($siteId);
        });
    }

    private function seedSitemap(int $siteId): SitemapSettingsRecord
    {
        return $this->seedRecord(SitemapSettingsRecord::class, $siteId, function(SitemapSettingsRecord $r): void {
            $r->sections = '[]';
            $r->excludeSections = '[]';
            $r->priority = '0.8';
            $r->changefreq = 'weekly';
            $r->sectionSitemap = '{}';
            $r->geoMarkdownFrontMatter = '{}';
        });
    }

    /**
     * @return array<string, SectionSitemapOverride>
     */
    private function decodeSectionSitemap(string $json): array
    {
        /** @var array<string, SectionSitemapOverride> $out */
        $out = $this->decodeHandleMap($json, static function(array $row): array {
            $part = [];
            if (isset($row['priority']) && is_numeric($row['priority'])) {
                $part['priority'] = max(0.0, min(1.0, (float) $row['priority']));
            }
            if (
                isset($row['changefreq']) &&
                is_string($row['changefreq']) &&
                SitemapSettings::isValidChangefreq($row['changefreq'])
            ) {
                $part['changefreq'] = $row['changefreq'];
            }
            return $part;
        });

        return $out;
    }

    /**
     * @return array<string, array<string, scalar|null>>
     */
    private function decodeGeoMarkdownFrontMatter(string $json): array
    {
        /** @var array<string, array<string, scalar|null>> $out */
        $out = $this->decodeHandleMap($json, static function(array $row): array {
            return array_filter(
                array_intersect_key($row, array_flip(array_filter(array_keys($row), static fn(mixed $k): bool => is_string($k) && $k !== ''))),
                static fn(mixed $v): bool => $v === null || is_scalar($v),
            );
        });

        return $out;
    }

    /**
     * Decode a JSON object keyed by section handle, mapping each row through
     * $mapRow. Non-string/empty keys and non-array rows are skipped; rows that
     * map to an empty array are dropped.
     *
     * @param callable(array<mixed, mixed>): array<mixed, mixed> $mapRow
     * @return array<string, array<mixed, mixed>>
     */
    private function decodeHandleMap(string $json, callable $mapRow): array
    {
        $json = trim($json);
        if ($json === '{}' || $json === '' || $json === '[]') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $handle => $row) {
            if (!is_string($handle) || $handle === '' || !is_array($row)) {
                continue;
            }
            $mapped = $mapRow($row);
            if ($mapped !== []) {
                $out[$handle] = $mapped;
            }
        }
        return $out;
    }

    private function seedLlms(int $siteId): LlmsSettingsRecord
    {
        return $this->seedRecord(LlmsSettingsRecord::class, $siteId, function(LlmsSettingsRecord $r): void {
            $r->enabled = true;
            $r->summary = null;
            $r->siteNameOverride = null;
            $r->sections = '[]';
        });
    }

    private function seedHumans(int $siteId): HumansSettingsRecord
    {
        return $this->seedRecord(HumansSettingsRecord::class, $siteId, function(HumansSettingsRecord $r): void {
            $r->enabled = false;
            $r->body = null;
        });
    }

    private function seedAds(int $siteId): AdsSettingsRecord
    {
        return $this->seedRecord(AdsSettingsRecord::class, $siteId, function(AdsSettingsRecord $r): void {
            $r->enabled = false;
            $r->assetId = null;
            $r->body = null;
        });
    }

    private function seedRobots(int $siteId): RobotsSettingsRecord
    {
        return $this->seedRecord(RobotsSettingsRecord::class, $siteId, function(RobotsSettingsRecord $r): void {
            $r->sitemapUrl = 'auto';
            $r->userAgentRules = '[]';
        });
    }

    private function seedWebmaster(int $siteId): WebmasterSettingsRecord
    {
        return $this->seedRecord(WebmasterSettingsRecord::class, $siteId, function(WebmasterSettingsRecord $r): void {
            $r->indexNowKey = null;
        });
    }

    /**
     * Find-or-create per-site record. Sets `siteId` + timestamps; calls
     * `$applyDefaults` to fill seed values when constructing a new row.
     *
     * @template T of \yii\db\ActiveRecord
     * @param class-string<T> $recordClass
     * @param callable(T): void $applyDefaults
     * @return T
     */
    private function seedRecord(string $recordClass, int $siteId, callable $applyDefaults): \yii\db\ActiveRecord
    {
        /** @var T|null $existing */
        $existing = $recordClass::findOne(['siteId' => $siteId]);
        if ($existing !== null) {
            return $existing;
        }
        /** @var T $record */
        $record = new $recordClass();
        $record->setAttribute('siteId', $siteId);
        $applyDefaults($record);
        $now = Db::now();
        $record->setAttribute('dateCreated', $now);
        $record->setAttribute('dateUpdated', $now);
        $record->save(false);
        return $record;
    }

    private function touchAndSave(\yii\db\ActiveRecord $record): void
    {
        $record->setAttribute('dateUpdated', Db::now());
        $record->save(false);
    }

    /**
     * @return list<UserAgentRule>
     */
    private function parseUserAgentRules(?string $json): array
    {
        $decoded = Json::decodeAssoc($json);
        if ($decoded === null) {
            return [];
        }

        $rules = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userAgent = $row['userAgent'] ?? null;
            if (!is_string($userAgent) || $userAgent === '') {
                continue;
            }
            /** @var UserAgentRule $rule */
            $rule = ['userAgent' => $userAgent];
            foreach (['allow', 'disallow'] as $key) {
                if (!isset($row[$key]) || !is_array($row[$key])) {
                    continue;
                }
                $paths = array_values(array_filter($row[$key], 'is_string'));
                if ($paths !== []) {
                    $rule[$key] = $paths;
                }
            }
            $rules[] = $rule;
        }

        return $rules;
    }
}
