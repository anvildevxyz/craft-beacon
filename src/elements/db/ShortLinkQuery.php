<?php

namespace anvildev\beacon\elements\db;

use anvildev\beacon\elements\ShortLinkElement;
use craft\elements\db\ElementQuery;

/**
 * @extends ElementQuery<int, ShortLinkElement>
 *
 * @method ShortLinkElement|null one($db = null)
 * @method ShortLinkElement|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ShortLinkQuery extends ElementQuery
{
    /**
     * Craft 6 never calls {@see beforePrepare()} (see its unchanged body
     * below) — this joins `beacon_short_links` in its place. Craft 5's own
     * `ElementQuery` declares no `$table` property at all, so this is an
     * unused extra property there, not a conflict. `'**'` (the default
     * select, set by the real Craft 6 constructor before this class gets a
     * chance to run anything) only expands to `elements`/`elements_sites`'
     * own columns, not this table's — {@see elementQueryBeforeQuery()}
     * below adds those explicitly.
     */
    protected string $table = 'beacon_short_links';

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('beacon_short_links');
        $this->query->select([
            'beacon_short_links.propagationMethod',
            'beacon_short_links.slug',
            'beacon_short_links.destination',
            'beacon_short_links.statusCode',
            'beacon_short_links.clicks',
            'beacon_short_links.lastClicked',
            'beacon_short_links.expiresAt',
            'beacon_short_links.note',
        ]);

        return parent::beforePrepare();
    }

    /**
     * The real Craft 6 hook replacing {@see beforePrepare()} (see above) —
     * declared only here, never on Craft 5's `ElementQuery`, so it's
     * inert, not a conflict, on that platform.
     */
    protected function elementQueryBeforeQuery(): void
    {
        parent::elementQueryBeforeQuery();

        $this->query->addSelect([
            'beacon_short_links.propagationMethod',
            'beacon_short_links.slug',
            'beacon_short_links.destination',
            'beacon_short_links.statusCode',
            'beacon_short_links.clicks',
            'beacon_short_links.lastClicked',
            'beacon_short_links.expiresAt',
            'beacon_short_links.note',
        ]);
    }
}
