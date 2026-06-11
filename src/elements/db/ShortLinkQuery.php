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
}
