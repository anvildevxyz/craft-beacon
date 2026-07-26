<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\RecordCache;
use anvildev\beacon\models\Schema;
use anvildev\beacon\records\SchemaRecord;
use yii\base\Component;

class BundleRegistry extends Component
{
    /** @var array<string,list<Schema>>|null */
    private ?array $cached = null;

    /**
     * @return list<Schema>
     */
    public function getSchemasForEntryType(string $entryTypeHandle): array
    {
        return $this->getAll()[$entryTypeHandle] ?? [];
    }

    /**
     * @return array<string,list<Schema>>
     */
    private function getAll(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        // Read on every front-end page render that emits JSON-LD, but only
        // written from the schemas CP section.
        /** @var array<string,list<Schema>> $grouped */
        $grouped = RecordCache::remember(
            'schemas.enabledByEntryType',
            [SchemaRecord::CACHE_TAG],
            function(): array {
                /** @var list<SchemaRecord> $records */
                $records = SchemaRecord::find()
                    ->where(['enabled' => true])
                    ->orderBy(['entryTypeHandle' => SORT_ASC, 'sortOrder' => SORT_ASC])
                    ->all();

                return array_reduce(
                    $records,
                    function(array $carry, SchemaRecord $r): array {
                        $carry[$r->entryTypeHandle][] = $this->toModel($r);
                        return $carry;
                    },
                    [],
                );
            },
        );

        return $this->cached = $grouped;
    }

    public function clearCache(): void
    {
        $this->cached = null;
    }

    private function toModel(SchemaRecord $r): Schema
    {
        $mapping = json_decode((string) $r->mapping, true);
        return new Schema(
            id: (int) $r->id,
            entryTypeHandle: (string) $r->entryTypeHandle,
            schemaType: (string) $r->schemaType,
            mapping: is_array($mapping) ? $mapping : [],
            sortOrder: (int) $r->sortOrder,
            enabled: (bool) $r->enabled,
        );
    }
}
