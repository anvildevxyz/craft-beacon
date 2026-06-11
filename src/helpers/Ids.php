<?php

namespace anvildev\beacon\helpers;

/**
 * Helpers for normalizing element-ID input from field values and form posts.
 */
final class Ids
{
    /**
     * Casts each value to int and keeps only positive results, reindexed.
     * ID inputs arrive as int|string mixes from field data and posted forms.
     *
     * @param array<mixed> $values
     * @return list<int>
     */
    public static function positiveInts(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn($id): int => (int) $id, $values),
            static fn(int $id): bool => $id > 0,
        ));
    }
}
