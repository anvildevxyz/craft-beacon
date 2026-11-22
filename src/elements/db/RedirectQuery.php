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
    /**
     * Craft 6 never calls {@see beforePrepare()} (see its unchanged body
     * below) — this joins `beacon_redirects` in its place. Craft 5's own
     * `ElementQuery` declares no `$table` property at all, so this is an
     * unused extra property there, not a conflict. `'**'` (the default
     * select, set by the real Craft 6 constructor before this class gets a
     * chance to run anything) only expands to `elements`/`elements_sites`'
     * own columns, not this table's — {@see elementQueryBeforeQuery()}
     * below adds those explicitly.
     */
    protected string $table = 'beacon_redirects';

    public function init(): void
    {
        // Redirects live in a single precedence structure, so the native index
        // can order by it (drag-to-reorder). Mirror CategoryQuery.
        if (!isset($this->withStructure)) {
            $this->withStructure = true;
        }

        // Bind the query to the one redirect structure unless a caller set
        // it. Originally only done in beforePrepare() (Craft 5's lazy,
        // pre-execution hook); duplicated here too since Craft 6 never
        // calls that hook at all (see beforePrepare() below) — guarded by
        // the same isset() check, so running it from both places is
        // harmless on Craft 5.
        if (!isset($this->structureId)) {
            $this->structureId = RedirectStructure::structureId() ?? false;
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

    /**
     * The real Craft 6 hook replacing {@see beforePrepare()} (see above) —
     * declared only here, never on Craft 5's `ElementQuery`, so it's
     * inert, not a conflict, on that platform.
     */
    protected function elementQueryBeforeQuery(): void
    {
        parent::elementQueryBeforeQuery();

        $this->query->addSelect([
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
    }
}
