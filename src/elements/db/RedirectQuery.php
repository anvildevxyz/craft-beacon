<?php

namespace anvildev\beacon\elements\db;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\helpers\RedirectStructure;
use craft\elements\db\ElementQuery;

/**
 * @extends ElementQuery<int, RedirectElement>
 *
 * @method RedirectElement|null one($db = null)
 * @method RedirectElement|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class RedirectQuery extends ElementQuery
{
    public function init(): void
    {
        // Redirects live in a single precedence structure, so the native index
        // can order by it (drag-to-reorder). Mirror CategoryQuery.
        if (!isset($this->withStructure)) {
            $this->withStructure = true;
        }
        parent::init();
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('beacon_redirects');

        // Bind the query to the one redirect structure unless a caller set it.
        if (!isset($this->structureId)) {
            $this->structureId = RedirectStructure::structureId() ?? false;
        }

        $this->query->select([
            'beacon_redirects.propagationMethod',
            'beacon_redirects.sourceUri',
            'beacon_redirects.targetUri',
            'beacon_redirects.statusCode',
            'beacon_redirects.type',
            'beacon_redirects.queryStringMode',
            'beacon_redirects.hits',
            'beacon_redirects.lastHit',
            'beacon_redirects.note',
            'beacon_redirects.source',
            'beacon_redirects.sortOrder',
            'beacon_redirects.elementId as attachedElementId',
            'beacon_redirects.elementSiteId as attachedElementSiteId',
        ]);

        return parent::beforePrepare();
    }
}
