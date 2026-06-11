<?php

namespace anvildev\beacon\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $sections
 * @property string $excludeSections
 * @property string $priority
 * @property string $changefreq
 * @property string|null $sectionSitemap JSON map section handle → {priority?,changefreq?}; null/empty reads as `{}` in PHP (MySQL: no LONGTEXT default)
 * @property string|null $geoMarkdownFrontMatter JSON map section handle → {key: value}; null/empty reads as `{}`
 * @property array<int,string>|string|null $newsSections
 */
class SitemapSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%beacon_sitemap_settings}}';
    }
}
