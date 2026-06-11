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
}
