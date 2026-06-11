<?php

namespace anvildev\beacon\services;

use anvildev\beacon\Plugin;
use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use yii\base\Component;

/**
 * @phpstan-import-type HreflangAlternate from \anvildev\beacon\models\SeoMeta
 */
class HreflangService extends Component
{
    /**
     * @return list<HreflangAlternate>
     */
    public function resolveAlternates(?Entry $entry): array
    {
        if ($entry === null || !$entry->id) {
            return [];
        }

        $settings = Plugin::$plugin->settings->get();
        if (!$settings->hreflangEnabled) {
            return [];
        }
        // No alternates to emit on a single-site install — short-circuit
        // before doing any element queries.
        $sites = Craft::$app->getSites();
        if (count($sites->getAllSites()) < 2) {
            return [];
        }

        /** @var array<int, Entry> $bySite */
        $bySite = [(int) $entry->siteId => $entry];
        $localizedQuery = $entry->getLocalized();
        if ($localizedQuery instanceof ElementQueryInterface) {
            foreach (
                $localizedQuery
                    ->status(Entry::STATUS_LIVE)
                    ->orderBy(['siteId' => SORT_ASC])
                    ->all() as $localized
            ) {
                if ($localized instanceof Entry && $localized->siteId !== $entry->siteId) {
                    $bySite[(int) $localized->siteId] = $localized;
                }
            }
        }

        $alternates = [];
        foreach ($bySite as $siteId => $variant) {
            $url = $variant->getUrl();
            $lang = trim((string) $sites->getSiteById($siteId)?->language);
            if ($url === null || $url === '' || $lang === '') {
                continue;
            }
            $alternates[] = [
                'hreflang' => $lang,
                'href' => $this->normalizeUrl($url),
            ];
        }

        $xDefaultHref = $this->resolveXDefaultHref($settings->hreflangXDefaultSiteHandle, $bySite);
        if ((string) $xDefaultHref !== '') {
            $alternates[] = ['hreflang' => 'x-default', 'href' => $this->normalizeUrl($xDefaultHref)];
        }

        usort($alternates, fn($a, $b) => strcmp($a['hreflang'], $b['hreflang']));

        if (count($alternates) <= 1) {
            return [];
        }

        return $alternates;
    }

    /**
     * @param array<int, Entry> $bySite
     */
    private function resolveXDefaultHref(?string $handle, array $bySite): ?string
    {
        $sites = Craft::$app->getSites();
        $siteModel = (is_string($handle) && trim($handle) !== '')
            ? $sites->getSiteByHandle(trim($handle))
            : null;
        $siteModel ??= $sites->getPrimarySite();

        $variant = $bySite[$siteModel->id] ?? reset($bySite);

        return $variant instanceof Entry ? $variant->getUrl() : null;
    }

    private function normalizeUrl(string $url): string
    {
        if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return UrlHelper::siteUrl($url);
        }
        return $url;
    }
}
