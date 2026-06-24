<?php

namespace anvildev\beacon\jobs\traits;

use anvildev\beacon\helpers\links\VolumeUrlResolver;
use anvildev\beacon\services\Links;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;

/**
 * @property int $siteId
 */
trait IndexesEntries
{
    /** @var array<string, int>|null */
    private ?array $volumePrefixMap = null;

    /** @return array<string, int> */
    private function getVolumePrefixMap(): array
    {
        if ($this->volumePrefixMap === null) {
            $volumes = [];
            foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
                $baseUrl = $volume->getRootUrl();
                if ($baseUrl === null) {
                    continue;
                }
                $volumes[] = ['id' => (int) $volume->id, 'baseUrl' => $baseUrl];
            }
            $this->volumePrefixMap = VolumeUrlResolver::buildPrefixMap($volumes);
        }
        return $this->volumePrefixMap;
    }

    private function resolveAssetFromUrl(string $url): ?Asset
    {
        $match = VolumeUrlResolver::matchPrefix($url, $this->getVolumePrefixMap());
        if ($match === null) {
            return null;
        }
        $path = VolumeUrlResolver::stripTransformSegment($match['path']);
        // Split folder path from filename
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            $folderPath = '';
            $filename = $path;
        } else {
            $folderPath = substr($path, 0, $lastSlash);
            $filename = substr($path, $lastSlash + 1);
        }
        if ($filename === '') {
            return null;
        }
        // Decode URL-encoded characters in the filename
        $filename = rawurldecode($filename);
        $query = Asset::find()
            ->volumeId($match['volumeId'])
            ->filename($filename);
        if ($folderPath !== '') {
            $query->folderPath(rawurldecode($folderPath));
        }
        $asset = $query->one();
        return $asset instanceof Asset ? $asset : null;
    }

    /**
     * Collect field content from an entry and all its nested entries (matrix blocks).
     *
     * @return array<string, string>
     */
    private function collectAllFieldContent(Entry $entry, Links $plugin): array
    {
        $fieldHandles = $this->getFieldHandles($entry);
        $allContent = $plugin->index->getEntryFieldContent($entry, $fieldHandles);

        // Recursively collect from nested entries
        $nested = Entry::find()->ownerId($entry->id)->siteId($this->siteId)->status(null)->all();
        foreach ($nested as $child) {
            $childHandles = $this->getFieldHandles($child);
            $childContent = $plugin->index->getEntryFieldContent($child, $childHandles);
            foreach ($childContent as $handle => $content) {
                $key = "nested_{$child->id}_{$handle}";
                $allContent[$key] = $content;
            }
            // Go deeper for doubly-nested entries
            $grandchildren = Entry::find()->ownerId($child->id)->siteId($this->siteId)->status(null)->all();
            foreach ($grandchildren as $grandchild) {
                $gcHandles = $this->getFieldHandles($grandchild);
                $gcContent = $plugin->index->getEntryFieldContent($grandchild, $gcHandles);
                foreach ($gcContent as $handle => $content) {
                    $key = "nested_{$grandchild->id}_{$handle}";
                    $allContent[$key] = $content;
                }
            }
        }

        return $allContent;
    }

    /**
     * Walk up the owner chain to find the root section-level entry.
     * In Craft 5, matrix blocks are nested entries with ownerId set.
     * Caps traversal at 20 levels to guard against cyclic or corrupt owner data.
     */
    private function resolveRootOwnerId(Entry $entry): int
    {
        $current = $entry;
        $depth = 0;
        while ($current->ownerId !== null) {
            if (++$depth > 20) {
                Craft::warning("resolveRootOwnerId: depth cap reached for entry {$entry->id}", 'beacon');
                break;
            }
            $owner = $current->getOwner();
            if ($owner === null || !$owner instanceof Entry) {
                break;
            }
            $current = $owner;
        }
        return $current->id;
    }

    /** @return string[] */
    private function getFieldHandles(Entry $entry): array
    {
        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return [];
        }
        $handles = [];
        foreach ($layout->getCustomFields() as $field) {
            $class = get_class($field);
            $classLower = strtolower($class);
            if (str_contains($classLower, 'ckeditor') || str_contains($classLower, 'plaintext')) {
                $handles[] = $field->handle;
            }
        }
        return $handles;
    }

    /**
     * Collect entry relations from Entries fields (and Hyper link fields pointing
     * to entries) on the given entry and all its nested entries. These relations
     * represent navigable links for click-depth even though they are not <a> tags
     * in HTML content.
     *
     * @return array<int, array{targetElementId: int, targetSiteId: int, targetElementType: string, fieldHandle: string, anchorText: string, isExternal: bool, targetUrl: string}>
     */
    private function collectEntryRelations(Entry $entry): array
    {
        $relations = [];
        $this->extractRelationsFromEntry($entry, $relations);

        $nested = Entry::find()->ownerId($entry->id)->siteId($this->siteId)->status(null)->all();
        foreach ($nested as $child) {
            $this->extractRelationsFromEntry($child, $relations);
            $grandchildren = Entry::find()->ownerId($child->id)->siteId($this->siteId)->status(null)->all();
            foreach ($grandchildren as $grandchild) {
                $this->extractRelationsFromEntry($grandchild, $relations);
            }
        }

        return $relations;
    }

    /** @param array<int, array{targetElementId: int, targetSiteId: int, targetElementType: string, fieldHandle: string, anchorText: string, isExternal: bool, targetUrl: string}> &$relations */
    private function extractRelationsFromEntry(Entry $entry, array &$relations): void
    {
        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return;
        }

        foreach ($layout->getCustomFields() as $field) {
            $class = get_class($field);
            $classLower = strtolower($class);

            // craft\fields\Entries — standard relation field
            if (str_contains($classLower, 'craft\\fields\\entries')) {
                $value = $entry->getFieldValue($field->handle);
                if ($value instanceof \craft\elements\db\EntryQuery) {
                    foreach ($value->all() as $related) {
                        if (!$related instanceof Entry) {
                            continue;
                        }
                        $targetId = $related->id;
                        if ($related->ownerId !== null) {
                            $targetId = $this->resolveRootOwnerId($related);
                        }
                        $relations[] = [
                            'targetElementId' => $targetId,
                            'targetSiteId' => $this->siteId,
                            'targetElementType' => Entry::class,
                            'fieldHandle' => $field->handle,
                            'anchorText' => $related->title ?? '',
                            'isExternal' => false,
                            'targetUrl' => $related->getUrl() ?? '',
                        ];
                    }
                }
            }

            // verbb\hyper\fields\HyperField — link field that may point to entries
            if (str_contains($classLower, 'hyper')) {
                try {
                    $value = $entry->getFieldValue($field->handle);
                    if ($value !== null && is_iterable($value)) {
                        foreach ($value as $link) {
                            if (!is_object($link) || !method_exists($link, 'getElement')) {
                                continue;
                            }
                            $linked = $link->getElement();
                            if (!$linked instanceof Entry) {
                                continue;
                            }
                            $targetId = $linked->id;
                            if ($linked->ownerId !== null) {
                                $targetId = $this->resolveRootOwnerId($linked);
                            }
                            $relations[] = [
                                'targetElementId' => $targetId,
                                'targetSiteId' => $this->siteId,
                                'targetElementType' => Entry::class,
                                'fieldHandle' => $field->handle,
                                'anchorText' => method_exists($link, 'getLinkText') ? ($link->getLinkText() ?? '') : '',
                                'isExternal' => false,
                                'targetUrl' => $linked->getUrl() ?? '',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    Craft::warning("Beacon: Failed to extract Hyper links from {$field->handle} on entry {$entry->id}: {$e->getMessage()}", 'beacon');
                }
            }
        }
    }

    /**
     * Resolve extracted links to their target elements.
     *
     * @param array<int, array{url: string, isExternal: bool, fieldHandle: string, anchorText: string}> $extractedLinks
     * @return array<int, array{targetElementId: int|null, targetSiteId: int|null, targetElementType: string|null, fieldHandle: string, anchorText: string, isExternal: bool, targetUrl: string}>
     */
    private function resolveLinks(array $extractedLinks, int $sourceEntryId, string $siteUrl): array
    {
        $resolvedLinks = [];
        foreach ($extractedLinks as $link) {
            if ($link['isExternal']) {
                // Try asset resolution before treating as truly external
                $asset = $this->resolveAssetFromUrl($link['url']);
                if ($asset !== null) {
                    $resolvedLinks[] = [
                        'targetElementId' => $asset->id,
                        'targetSiteId' => $this->siteId,
                        'targetElementType' => Asset::class,
                        'fieldHandle' => $link['fieldHandle'],
                        'anchorText' => $link['anchorText'],
                        'isExternal' => false,
                        'targetUrl' => $link['url'],
                    ];
                    continue;
                }
                $resolvedLinks[] = [
                    'targetElementId' => null,
                    'targetSiteId' => null,
                    'targetElementType' => null,
                    'fieldHandle' => $link['fieldHandle'],
                    'anchorText' => $link['anchorText'],
                    'isExternal' => true,
                    'targetUrl' => $link['url'],
                ];
                continue;
            }
            // Strip only the leading site-URL prefix; str_replace() would remove
            // every occurrence and corrupt the URI when the host string repeats
            // in the path or query.
            $url = $link['url'];
            $uri = str_starts_with($url, $siteUrl) ? substr($url, strlen($siteUrl)) : $url;
            $element = Craft::$app->getElements()->getElementByUri(
                ltrim($uri, '/'),
                $this->siteId,
            );
            if ($element !== null) {
                // Resolve target to root owner too (in case link points to a nested entry URL)
                $targetId = $element->id;
                $targetElementType = get_class($element);
                if ($element instanceof Entry && $element->ownerId !== null) {
                    $targetId = $this->resolveRootOwnerId($element);
                }
                // Don't link to self
                if ($targetId === $sourceEntryId) {
                    continue;
                }
                $resolvedLinks[] = [
                    'targetElementId' => $targetId,
                    'targetSiteId' => $this->siteId,
                    'targetElementType' => $targetElementType,
                    'fieldHandle' => $link['fieldHandle'],
                    'anchorText' => $link['anchorText'],
                    'isExternal' => false,
                    'targetUrl' => $link['url'],
                ];
            }
        }

        return $resolvedLinks;
    }
}
