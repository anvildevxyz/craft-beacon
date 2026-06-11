<?php

namespace anvildev\beacon\services;

use anvildev\beacon\helpers\SeoFieldReader;
use anvildev\beacon\helpers\Xml;
use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\Section;
use yii\base\Component;

/**
 * Renders JSON Feed 1.1 and Atom 1.0 feeds for a single Craft section.
 *
 * Returns null when the section has no live entries — the caller should turn
 * that into a 404. Rendering is on demand; if perf becomes an issue, wrap in
 * RenderCache.
 */
class FeedService extends Component
{
    public const FEED_LIMIT = 50;

    /**
     * @return list<Entry>
     */
    public function fetchEntries(int $siteId, string $sectionHandle): array
    {
        return Entry::find()
            ->section($sectionHandle)
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->orderBy(['postDate' => SORT_DESC, 'dateUpdated' => SORT_DESC])
            ->limit(self::FEED_LIMIT)
            ->all();
    }

    /**
     * @param list<Entry> $entries
     */
    public function renderJsonFeed(string $siteName, string $siteUrl, string $feedUrl, string $sectionHandle, array $entries): string
    {
        $items = [];
        foreach ($entries as $entry) {
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null) {
                continue;
            }
            $items[] = array_filter([
                'id' => $url,
                'url' => $url,
                'title' => (string) $entry->title,
                'date_published' => $entry->postDate?->format(\DateTimeInterface::ATOM),
                'date_modified' => $entry->dateUpdated?->format(\DateTimeInterface::ATOM),
                'summary' => SeoFieldReader::readDescriptionFor($entry),
            ], fn($v) => $v !== null && $v !== '');
        }

        $feed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $siteName . ' — ' . $sectionHandle,
            'home_page_url' => $siteUrl,
            'feed_url' => $feedUrl,
            'items' => $items,
        ];

        return Json::encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param list<Entry> $entries
     */
    public function renderAtomFeed(string $siteName, string $siteUrl, string $feedUrl, string $sectionHandle, array $entries): string
    {
        // ATOM timestamps are lexically ordered, so string max() yields the latest.
        $updated = max('1970-01-01T00:00:00Z', ...array_map(
            fn(Entry $e) => $e->dateUpdated?->format(\DateTimeInterface::ATOM) ?? '',
            $entries,
        ));

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";
        $xml .= '  <title>' . Xml::escape($siteName . ' — ' . $sectionHandle) . "</title>\n";
        $xml .= '  <link rel="self" href="' . Xml::escape($feedUrl) . "\"/>\n";
        $xml .= '  <link href="' . Xml::escape($siteUrl) . "\"/>\n";
        $xml .= '  <id>' . Xml::escape($feedUrl) . "</id>\n";
        $xml .= '  <updated>' . Xml::escape($updated) . "</updated>\n";

        foreach ($entries as $entry) {
            $url = SeoFieldReader::indexableUrl($entry);
            if ($url === null) {
                continue;
            }
            $xml .= "  <entry>\n";
            $xml .= '    <id>' . Xml::escape($url) . "</id>\n";
            $xml .= '    <title>' . Xml::escape((string) $entry->title) . "</title>\n";
            $xml .= '    <link href="' . Xml::escape($url) . "\"/>\n";
            if ($entry->postDate !== null) {
                $xml .= '    <published>' . Xml::escape($entry->postDate->format(\DateTimeInterface::ATOM)) . "</published>\n";
            }
            $xml .= '    <updated>' . Xml::escape($entry->dateUpdated?->format(\DateTimeInterface::ATOM) ?? $updated) . "</updated>\n";
            $summary = SeoFieldReader::readDescriptionFor($entry);
            if ($summary !== null) {
                $xml .= '    <summary>' . Xml::escape($summary) . "</summary>\n";
            }
            $xml .= "  </entry>\n";
        }

        $xml .= "</feed>\n";
        return $xml;
    }

    public function sectionExists(string $handle): bool
    {
        return Craft::$app->getEntries()->getSectionByHandle($handle) instanceof Section;
    }
}
