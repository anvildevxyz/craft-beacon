<?php

namespace anvildev\beacon\helpers;

/**
 * Iterates a Craft element query in memory-efficient batches, `foreach`-
 * compatible on both Craft 5 and Craft 6.
 *
 * Craft 5's `ElementQuery::each($batchSize)` is Yii's old batch-generator
 * method — call it directly and `foreach` over the result. Craft 6's
 * `ElementQuery` instead composes `Illuminate\Database\Concerns::
 * BuildsQueries`, whose `each(callable $callback, $count = 1000)` takes a
 * callback first and isn't `foreach`-able at all — `lazy($chunkSize)` is
 * that trait's closest equivalent (still internally chunked, still
 * `foreach`-able). There's no shared interface to branch on cleanly, so
 * this checks for the method directly: only Craft 6's class has `lazy()`
 * at all, while both versions' classes have an `each()` with incompatible
 * shapes.
 */
final class ElementBatch
{
    /**
     * @return iterable<\craft\base\ElementInterface>
     */
    public static function each(object $query, int $batchSize = 500): iterable
    {
        return method_exists($query, 'lazy') ? $query->lazy($batchSize) : $query->each($batchSize);
    }
}
