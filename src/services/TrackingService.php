<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\TrackingPlacement;
use anvildev\beacon\enums\TrackingProvider;
use anvildev\beacon\models\TrackingScript;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\TrackingScriptRecord;
use Craft;
use craft\events\ConfigEvent;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * @phpstan-import-type SiteOverrides from \anvildev\beacon\services\SiteOverrideResolver
 */
class TrackingService extends Component
{
    /**
     * @return list<TrackingScriptRecord>
     */
    public function list(): array
    {
        /** @var list<TrackingScriptRecord> $records */
        $records = TrackingScriptRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'dateCreated' => SORT_ASC])
            ->all();
        return $records;
    }

    public function findByUid(string $uid): ?TrackingScriptRecord
    {
        /** @var TrackingScriptRecord|null $record */
        $record = TrackingScriptRecord::find()->where(['uid' => $uid])->one();
        return $record;
    }

    /**
     * Returns active tracking-script rows for the given site/env, ordered by
     * sortOrder then dateCreated. Filters out scripts that are disabled in
     * the requested env or whose site override sets `enabled=false` for
     * the resolved site.
     *
     * @return array<int, array{
     *     uid: string,
     *     name: string,
     *     provider: string,
     *     config: array<string, mixed>,
     *     placement: string,
     *     sortOrder: int,
     *     siteOverrides: array<string, mixed>|null,
     * }>
     */
    public function getActiveScripts(int $siteId): array
    {
        /** @var list<TrackingScriptRecord> $rows */
        $rows = TrackingScriptRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'dateCreated' => SORT_ASC])
            ->all();

        $siteUid = Craft::$app->getSites()->getSiteById($siteId)?->uid;

        $active = [];
        foreach ($rows as $row) {
            $overrides = $this->normalizeOverrides($row->siteOverrides);
            if ($siteUid !== null && ($overrides[$siteUid]['enabled'] ?? true) === false) {
                continue;
            }
            $active[] = [
                'uid' => (string) $row->uid,
                'name' => (string) $row->name,
                'provider' => (string) $row->provider,
                'config' => $this->normalizeConfig($row->config),
                'placement' => (string) $row->placement,
                'sortOrder' => (int) $row->sortOrder,
                'siteOverrides' => $overrides,
            ];
        }

        return $active;
    }

    /**
     * Renders the concatenated HTML for a given placement on a site. Returns
     * an empty string for CP, console, or preview requests so admin-side and
     * draft views never see analytics noise. Output is cached per
     * site/env/placement and tagged with `beacon_tracking_scripts`.
     */
    public function renderPlacement(int $siteId, string $placement): string
    {
        if ($this->isAdminContext()) {
            return '';
        }
        return $this->cachedPlacement($siteId, EnvironmentMapper::resolveActive()->value, $placement);
    }

    /**
     * Renders scripts for an explicit environment key (`production`, `staging`,
     * `dev`). Useful for previewing/non-standard template composition.
     */
    public function renderPlacementWithEnv(int $siteId, string $placement, string $env): string
    {
        if ($this->isAdminContext()) {
            return '';
        }
        return $this->cachedPlacement($siteId, EnvironmentMapper::canonicalize($env)->value, $placement);
    }

    private function isAdminContext(): bool
    {
        $request = Craft::$app->getRequest();
        return $request->getIsCpRequest() || $request->getIsConsoleRequest() || $request->getIsPreview();
    }

    private function cachedPlacement(int $siteId, string $env, string $placement): string
    {
        $cacheKey = "beacon.tracking.{$siteId}.{$env}.{$placement}";
        return (string) Craft::$app->getCache()->getOrSet(
            $cacheKey,
            fn(): string => $this->buildPlacement($siteId, $env, $placement),
            null,
            new TagDependency(['tags' => [TrackingScriptRecord::CACHE_TAG]]),
        );
    }

    private function buildPlacement(int $siteId, string $env, string $placement): string
    {
        $target = TrackingPlacement::tryFrom($placement);
        if ($target === null) {
            return '';
        }

        $registry = Plugin::$plugin?->trackingRegistry;
        if ($registry === null) {
            return '';
        }
        $resolver = new SiteOverrideResolver();
        $primarySiteUid = Craft::$app->getSites()->getSiteById($siteId)?->uid ?? '';
        $output = '';

        foreach ($this->getActiveScripts($siteId) as $script) {
            $provider = $registry->get($script['provider']);
            if ($provider === null) {
                continue;
            }
            /** @var SiteOverrides|null $overrides */
            $overrides = $script['siteOverrides'];
            if ($resolver->isDisabledForSite($overrides, $primarySiteUid)) {
                continue;
            }
            $scriptPlacement = TrackingPlacement::tryFrom($script['placement']);
            $effective = $provider->getFixedPlacements()
                ?? ($scriptPlacement !== null ? [$scriptPlacement] : []);
            if (!in_array($target, $effective, true)) {
                continue;
            }
            $config = $resolver->resolve($script['config'], $overrides, $primarySiteUid);
            $output .= $provider->render($config, $target) . "\n";
        }
        return $output;
    }

    /**
     * @param array<string, mixed>|string|null $raw
     * @return array<string, mixed>
     */
    public function normalizeConfig(array|string|null $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param array<string, mixed>|string|null $raw
     * @return array<string, mixed>|null
     */
    public function normalizeOverrides(array|string|null $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Validates the model and writes its definition to Project Config under
     * `beacon.trackingScripts.{uid}`. The PC handler ({@see self::handleChangedScript()})
     * then materialises the row in the database.
     */
    public function saveScript(TrackingScript $script): bool
    {
        if (!$script->validate()) {
            return false;
        }
        $script->uid ??= StringHelper::UUID();
        Craft::$app->getProjectConfig()->set("beacon.trackingScripts.{$script->uid}", [
            'name' => $script->name,
            'provider' => $script->provider,
            'config' => $script->config,
            'placement' => $script->placement,
            'sortOrder' => $script->sortOrder,
            'siteOverrides' => $script->siteOverrides,
        ]);
        return true;
    }

    /**
     * Removes a tracking-script definition from Project Config; the PC handler
     * ({@see self::handleDeletedScript()}) deletes the underlying record.
     */
    public function deleteScript(string $uid): void
    {
        Craft::$app->getProjectConfig()->remove("beacon.trackingScripts.{$uid}");
    }

    /**
     * Project Config handler for added/updated `beacon.trackingScripts.{uid}`
     * paths. Creates or updates the matching {@see TrackingScriptRecord}.
     */
    public function handleChangedScript(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;
        if (!is_array($data)) {
            return;
        }

        /** @var TrackingScriptRecord $record */
        $record = TrackingScriptRecord::find()->where(['uid' => $uid])->one()
            ?? new TrackingScriptRecord(['uid' => $uid]);

        $record->name = (string) ($data['name'] ?? '');
        $record->provider = (string) ($data['provider'] ?? TrackingProvider::Custom->value);
        $record->config = is_array($data['config'] ?? null) ? $data['config'] : [];
        $record->placement = (string) ($data['placement'] ?? TrackingPlacement::Head->value);
        $record->sortOrder = (int) ($data['sortOrder'] ?? 0);
        $record->siteOverrides = is_array($data['siteOverrides'] ?? null) ? $data['siteOverrides'] : null;
        $record->save();
    }

    /**
     * Project Config handler for removed `beacon.trackingScripts.{uid}` paths.
     * Deletes the matching {@see TrackingScriptRecord}, if any. Uses per-row
     * `delete()` instead of static `deleteAll()` so the record's
     * {@see TrackingScriptRecord::afterDelete()} hook fires and the
     * `beacon_tracking_scripts` cache tag is invalidated.
     */
    public function handleDeletedScript(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $record = TrackingScriptRecord::find()->where(['uid' => $uid])->one();
        if ($record instanceof TrackingScriptRecord) {
            $record->delete();
        }
    }
}
