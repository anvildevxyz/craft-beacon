<?php

namespace anvildev\beacon\services;

use anvildev\beacon\enums\GeoScorePillar;
use anvildev\beacon\events\RegisterGeoScorePillarsEvent;
use anvildev\beacon\helpers\GeoScoreLookup;
use anvildev\beacon\models\GeoPillarScore;
use anvildev\beacon\models\GeoScore;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\GeoScoreRecord;
use anvildev\beacon\services\scoring\ChunkabilityPillar;
use anvildev\beacon\services\scoring\ClaimBasedHeadingsPillar;
use anvildev\beacon\services\scoring\EntityCompletenessPillar;
use anvildev\beacon\services\scoring\FactDensityPillar;
use anvildev\beacon\services\scoring\FreshnessBandingPillar;
use anvildev\beacon\services\scoring\OutboundCitationDensityPillar;
use anvildev\beacon\services\scoring\PillarComputerInterface;
use anvildev\beacon\services\scoring\PillarContext;
use Craft;
use craft\base\ElementInterface;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use yii\base\Component;
use yii\base\Event;

/**
 * Orchestrates per-(element, site) GEO scoring. Dispatches to registered
 * {@see PillarComputerInterface} implementations, computes the weighted
 * 0–100 composite, persists to {{%beacon_geo_score}}.
 *
 * `sourceHash` short-circuits recompute when the same element + same
 * relevant settings + same dateUpdated already produced a score. Site
 * config changes (Organization JSON-LD, weight overrides) bump the hash
 * input and force a fresh compute on next save.
 *
 * Six built-in pillars (Freshness, Entity Completeness, Claim-Based Headings,
 * Chunkability, Fact Density, Outbound Citation Density) register as defaults;
 * third parties add or replace them via
 * {@see Plugin::EVENT_REGISTER_GEO_SCORE_PILLARS}.
 *
 * @phpstan-type GeoScoreDistribution array{stale:int, low:int, fair:int, good:int, top:int, total:int}
 * @phpstan-type GeoWeakestPillarRow array{
 *   elementId:int,
 *   siteId:int,
 *   score:int,
 *   title:string,
 *   url:?string,
 *   weakestPillar:?string,
 *   weakestPillarLabel:?string,
 *   weakestPillarScore:?int
 * }
 */
class GeoScoreService extends Component
{
    /**
     * Fires once per request the first time {@see self::compute()} runs.
     *
     * Event class: {@see RegisterGeoScorePillarsEvent}
     *
     * @since 1.0.0
     */
    public const EVENT_REGISTER_GEO_SCORE_PILLARS = 'registerGeoScorePillars';

    /** @var list<PillarComputerInterface>|null */
    private ?array $pillars = null;

    /**
     * Compute (or return the cached) GEO score for an (element, site).
     *
     * `$persist` controls whether a fresh compute is written to the score
     * table. The async {@see RecomputeGeoScoreJob} persists (the default);
     * the SEO-field chip computes with `$persist = false` for instant display
     * because Craft renders fields inside the provisional-draft transaction —
     * a write there would roll back with the render. The persisted row still
     * arrives via the queue job enqueued on the same save.
     */
    public function compute(ElementInterface $element, int $siteId, bool $persist = true): GeoScore
    {
        $sourceHash = $this->sourceHashFor($element, $siteId);
        $existing = $this->forElement((int) ($element->id ?? 0), $siteId, $sourceHash);
        if ($existing !== null) {
            return $existing;
        }

        $ctx = new PillarContext($element, $siteId);

        /** @var array<string, GeoPillarScore> $pillarScores */
        $pillarScores = [];
        foreach ($this->getPillars() as $computer) {
            $score = $computer->compute($ctx);
            // Key by the score's handle (enum value or custom string), so a
            // third-party pillar with a string handle participates normally.
            $pillarScores[$score->pillarHandle()] = $score;
        }

        $composite = $this->composite($pillarScores);
        $geoScore = new GeoScore(
            score: $composite,
            pillars: $pillarScores,
            computedAt: new DateTimeImmutable(),
        );

        if ($persist) {
            $this->persist($geoScore, (int) $element->id, $siteId, $sourceHash);
        }
        return $geoScore;
    }

    /**
     * Read the cached score for an element. When `$expectedHash` is set,
     * returns null on hash mismatch — used by {@see self::compute()} to
     * skip recompute on identical inputs.
     */
    public function forElement(int $elementId, int $siteId, ?string $expectedHash = null): ?GeoScore
    {
        return GeoScoreLookup::forElement($elementId, $siteId, $expectedHash);
    }

    public function invalidate(int $elementId, ?int $siteId = null): void
    {
        $criteria = ['elementId' => $elementId];
        if ($siteId !== null) {
            $criteria['siteId'] = $siteId;
        }
        GeoScoreRecord::deleteAll($criteria);
    }

    /**
     * Site-level composite distribution histogram, bucketed into 5 bands
     * (0–19, 20–39, 40–59, 60–79, 80–100). The dashboard widget renders
     * these as a horizontal bar chart so operators see at a glance how
     * much of their content needs attention.
     *
     * @return GeoScoreDistribution
     */
    public function distribution(int $siteId): array
    {
        $rows = (new \yii\db\Query())
            ->select(['score'])
            ->from(GeoScoreRecord::tableName())
            ->where(['siteId' => $siteId])
            ->all();

        $buckets = ['stale' => 0, 'low' => 0, 'fair' => 0, 'good' => 0, 'top' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $score = (int) $row['score'];
            $buckets['total']++;
            $bucket = match (true) {
                $score >= 80 => 'top',
                $score >= 60 => 'good',
                $score >= 40 => 'fair',
                $score >= 20 => 'low',
                default => 'stale',
            };
            $buckets[$bucket]++;
        }
        return $buckets;
    }

    /**
     * Site-average composite (0–100), rounded. Returns null when no rows
     * exist yet — the widget renders an empty state in that case rather
     * than misleading 0/100.
     */
    public function siteAverage(int $siteId): ?int
    {
        $avg = (new \yii\db\Query())
            ->from(GeoScoreRecord::tableName())
            ->where(['siteId' => $siteId])
            ->average('score');
        return $avg === null ? null : (int) round($avg);
    }

    /**
     * For each row in the cached score table, enrich with element title +
     * URL and the entry's weakest pillar (the lowest-scoring pillar in
     * that entry's per-pillar JSON). Used by the dashboard widget to render
     * a "what to fix first" list — operators don't want a 50-row table,
     * they want the 5 rows with the most actionable single-pillar gap.
     *
     * Ordering: ascending by composite score (worst first), tie-broken by
     * weakest-pillar score (the entry whose weakest pillar is the most
     * lopsidedly bad surfaces first).
     *
     * @return list<GeoWeakestPillarRow>
     */
    public function weakestPillars(int $siteId, int $limit = 5): array
    {
        $rows = (new \yii\db\Query())
            ->select(['gs.elementId', 'gs.siteId', 'gs.score', 'gs.pillars', 'es.title', 'es.uri'])
            ->from(GeoScoreRecord::tableName() . ' gs')
            ->leftJoin('{{%elements_sites}} es', 'es.elementId = gs.elementId AND es.siteId = gs.siteId')
            ->where(['gs.siteId' => $siteId])
            ->orderBy(['gs.score' => SORT_ASC])
            ->limit(max(1, $limit * 3))
            ->all();

        $primaryBaseUrl = $this->siteBaseUrl($siteId);

        $out = array_map(function(array $row) use ($primaryBaseUrl): array {
            $weakest = $this->decodeWeakest($row['pillars']);
            $uri = is_string($row['uri']) && $row['uri'] !== '' ? $row['uri'] : null;
            return [
                'elementId' => (int) $row['elementId'],
                'siteId' => (int) $row['siteId'],
                'score' => (int) $row['score'],
                'title' => is_string($row['title']) ? $row['title'] : '',
                'url' => $uri !== null && $primaryBaseUrl !== null
                    ? rtrim($primaryBaseUrl, '/') . '/' . ltrim($uri, '/')
                    : null,
                'weakestPillar' => $weakest['handle'],
                'weakestPillarLabel' => $weakest['label'],
                'weakestPillarScore' => $weakest['score'],
            ];
        }, $rows);

        usort($out, static fn(array $a, array $b): int =>
            ($a['score'] <=> $b['score']) ?: (($a['weakestPillarScore'] ?? 10) <=> ($b['weakestPillarScore'] ?? 10))
        );

        return array_slice($out, 0, $limit);
    }

    /**
     * @param array<mixed, mixed>|string|null $pillarsJson
     * @return array{handle:?string, label:?string, score:?int}
     */
    private function decodeWeakest(array|string|null $pillarsJson): array
    {
        // The `pillars` column is declared JSON but the persist path encodes
        // it as a JSON string before storing, so MySQL ends up holding a
        // JSON-encoded JSON string. ActiveRecord auto-decodes the outer
        // layer; raw Query results don't, so we have to handle both shapes.
        $decoded = Json::decodeIfJson($pillarsJson);
        if (is_string($decoded)) {
            $decoded = Json::decodeIfJson($decoded);
        }
        if (!is_array($decoded)) {
            return ['handle' => null, 'label' => null, 'score' => null];
        }

        /** @var array{handle:string, score:int}|null $weakest */
        $weakest = array_reduce(
            array_keys($decoded),
            static function(?array $carry, mixed $handle) use ($decoded): ?array {
                if (!is_string($handle) || !is_array($decoded[$handle]) || !isset($decoded[$handle]['score'])) {
                    return $carry;
                }
                $score = (int) $decoded[$handle]['score'];
                return ($carry === null || $score < $carry['score'])
                    ? ['handle' => $handle, 'score' => $score]
                    : $carry;
            },
        );

        if ($weakest === null) {
            return ['handle' => null, 'label' => null, 'score' => null];
        }
        return [
            'handle' => $weakest['handle'],
            'label' => GeoScorePillar::tryFrom($weakest['handle'])?->label() ?? $weakest['handle'],
            'score' => $weakest['score'],
        ];
    }

    private function siteBaseUrl(int $siteId): ?string
    {
        $url = Craft::$app->getSites()->getSiteById($siteId)?->getBaseUrl();
        return is_string($url) ? $url : null;
    }

    /**
     * @return list<PillarComputerInterface>
     */
    private function getPillars(): array
    {
        if ($this->pillars !== null) {
            return $this->pillars;
        }

        $event = new RegisterGeoScorePillarsEvent();
        $event->pillars = [
            new FreshnessBandingPillar(),
            new EntityCompletenessPillar(),
            new ClaimBasedHeadingsPillar(),
            new ChunkabilityPillar(),
            new FactDensityPillar(),
            new OutboundCitationDensityPillar(),
        ];
        Event::trigger(self::class, self::EVENT_REGISTER_GEO_SCORE_PILLARS, $event);

        return $this->pillars = array_values($event->pillars);
    }

    /**
     * @param array<string, GeoPillarScore> $pillarScores
     */
    private function composite(array $pillarScores): int
    {
        $weights = $this->resolvedWeights();
        $weightedSum = 0.0;
        $weightTotal = 0.0;
        foreach ($pillarScores as $handle => $pillarScore) {
            $weight = $weights[$handle] ?? 1.0;
            $weightedSum += $pillarScore->score * $weight;
            $weightTotal += $weight;
        }
        if ($weightTotal <= 0.0) {
            return 0;
        }
        $normalised = $weightedSum / $weightTotal;  // 0–10
        return (int) round($normalised * 10);       // 0–100
    }

    /**
     * @return array<string, float>
     */
    private function resolvedWeights(): array
    {
        $weights = array_column(
            array_map(fn(GeoScorePillar $case): array => [$case->value, $case->defaultWeight()], GeoScorePillar::cases()),
            1,
            0,
        );

        foreach (Plugin::$plugin->settings->get()->geoScorePillarWeights as $handle => $weight) {
            if (is_string($handle) && is_numeric($weight)) {
                $weights[$handle] = (float) $weight;
            }
        }
        return $weights;
    }

    private function sourceHashFor(ElementInterface $element, int $siteId): string
    {
        $settings = Plugin::$plugin->settings->get();
        $inputs = [
            'elementId' => (int) ($element->id ?? 0),
            'siteId' => $siteId,
            'dateUpdated' => $element->dateUpdated?->format(\DATE_ATOM),
            'organizationName' => $settings->organizationName,
            'organizationLogoAssetId' => $settings->organizationLogoAssetId,
            'sameAsUrls' => $settings->sameAsUrls(),
            'weights' => $settings->geoScorePillarWeights,
            // Render mode + detection mode affect the AST inputs of structural
            // pillars, so they belong in the cache key: a change must invalidate
            // any cached score row.
            'renderMode' => $settings->effectiveGeoScoreRenderMode(),
            'claimMode' => $settings->geoScoreClaimDetectionMode,
            'factTarget' => $settings->geoScoreFactDensityTarget,
            'factMode' => $settings->geoScoreFactDetectionMode,
            'authorityOverrides' => $settings->geoScoreAuthorityDomainOverrides,
        ];
        return hash('sha256', Json::encode($inputs));
    }

    private function persist(GeoScore $score, int $elementId, int $siteId, string $sourceHash): void
    {
        $record = GeoScoreRecord::findOne([
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]) ?? new GeoScoreRecord();

        $record->elementId = $elementId;
        $record->siteId = $siteId;
        $record->score = $score->score;
        $record->pillars = Json::encode(array_map(static fn(GeoPillarScore $s): array => $s->toArray(), $score->pillars));
        $record->sourceHash = $sourceHash;
        $record->computedAt = Db::prepareDateForDb($score->computedAt);
        $record->save(false);
    }
}
