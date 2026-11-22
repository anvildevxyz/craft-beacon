<?php

namespace anvildev\beacon\elements\db;

use anvildev\beacon\elements\AuthorElement;
use craft\elements\db\ElementQuery;

/**
 * @extends ElementQuery<int, AuthorElement>
 *
 * @method AuthorElement|null one($db = null)
 * @method AuthorElement|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class AuthorQuery extends ElementQuery
{
    /**
     * Craft 6 never calls {@see beforePrepare()} (see its unchanged body
     * below) — this joins `beacon_authors` in its place. Craft 5's own
     * `ElementQuery` declares no `$table` property at all, so this is an
     * unused extra property there, not a conflict. `'**'` (the default
     * select, set by the real Craft 6 constructor before this class gets a
     * chance to run anything) only expands to `elements`/`elements_sites`'
     * own columns, not this table's — {@see elementQueryBeforeQuery()}
     * below adds those explicitly.
     */
    protected string $table = 'beacon_authors';

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('beacon_authors');
        $this->query->select([
            'beacon_authors.expertise',
            'beacon_authors.credentials',
            'beacon_authors.sameAs',
            'beacon_authors.jobTitle',
            'beacon_authors.imageAssetId',
            'beacon_authors.description',
            'beacon_authors.alumniOf',
            'beacon_authors.affiliation',
            'beacon_authors.worksFor',
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
            'beacon_authors.expertise',
            'beacon_authors.credentials',
            'beacon_authors.sameAs',
            'beacon_authors.jobTitle',
            'beacon_authors.imageAssetId',
            'beacon_authors.description',
            'beacon_authors.alumniOf',
            'beacon_authors.affiliation',
            'beacon_authors.worksFor',
        ]);
    }
}
