<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\Db;
use anvildev\beacon\records\GeoMarkdownRecord;
use Craft;
use craft\db\Query;
use Throwable;
use yii\base\Component;

/**
 * Persistence layer for pre-generated GEO Markdown.
 *
 * Reads bypass the AR layer for hot lookups; writes use AR for `dateUpdated` + `uid`.
 * On-demand fallback in {@see GeoMarkdownExportService} writes through here so the
 * second request is served from this table without re-rendering.
 *
 * Keyed by element id (not entry id) so Commerce Products and other element
 * types share the same store.
 */
class GeoMarkdownStore extends Component
{
    /**
     * Returns the cached row as an array, or null when absent.
     *
     * @return array{id:int,siteId:int,elementId:int,markdown:?string,hash:?string,dateGenerated:?string}|null
     */
    public function find(int $siteId, int $elementId): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = (new Query())
            ->select(['id', 'siteId', 'elementId', 'markdown', 'hash', 'dateGenerated'])
            ->from(['{{%beacon_geo_markdown}}'])
            ->where(['siteId' => $siteId, 'elementId' => $elementId])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'siteId' => (int) $row['siteId'],
            'elementId' => (int) $row['elementId'],
            'markdown' => is_string($row['markdown']) ? $row['markdown'] : null,
            'hash' => is_string($row['hash']) ? $row['hash'] : null,
            'dateGenerated' => is_string($row['dateGenerated']) ? $row['dateGenerated'] : null,
        ];
    }

    public function put(int $siteId, int $elementId, string $markdown): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $hash = hash('sha256', $markdown);
        $now = Db::now();

        $record = GeoMarkdownRecord::findOne(['siteId' => $siteId, 'elementId' => $elementId])
            ?? new GeoMarkdownRecord();

        if ($record->isNewRecord) {
            $record->siteId = $siteId;
            $record->elementId = $elementId;
            $record->dateCreated = $now;
        }

        if ($record->hash !== $hash || $record->markdown !== $markdown) {
            $record->markdown = $markdown;
            $record->hash = $hash;
            $record->dateGenerated = $now;
        }
        $record->dateRequested = $now;
        $record->dateUpdated = $now;

        try {
            $record->save(false);
        } catch (Throwable $e) {
            Craft::warning(
                sprintf(
                    'GEO markdown cache write failed for siteId=%d elementId=%d: %s',
                    $siteId,
                    $elementId,
                    $e->getMessage(),
                ),
                'beacon',
            );
        }
    }

    public function touchRequested(int $siteId, int $elementId): void
    {
        if (!$this->tableExists()) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%beacon_geo_markdown}}',
                ['dateRequested' => Db::now()],
                ['siteId' => $siteId, 'elementId' => $elementId],
            )
            ->execute();
    }

    public function clear(?int $siteId = null, ?int $elementId = null): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $where = array_filter([
            'siteId' => $siteId,
            'elementId' => $elementId,
        ], static fn($v): bool => $v !== null);

        return (int) Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%beacon_geo_markdown}}', $where ?: '')
            ->execute();
    }

    /**
     * IDs that have a pre-generated row in this table for the given site.
     *
     * @return list<int>
     */
    public function existingElementIds(int $siteId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return array_map(
            intval(...),
            (new Query())
                ->select(['elementId'])
                ->from(['{{%beacon_geo_markdown}}'])
                ->where(['siteId' => $siteId])
                ->andWhere(['not', ['markdown' => null]])
                ->column(),
        );
    }

    private function tableExists(): bool
    {
        return Craft::$app->getDb()->getTableSchema('{{%beacon_geo_markdown}}', true) !== null;
    }
}
