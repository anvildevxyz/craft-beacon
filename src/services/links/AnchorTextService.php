<?php

namespace anvildev\beacon\services\links;

use anvildev\beacon\records\LinkRecord;
use craft\base\Component;

class AnchorTextService extends Component
{
    public const DEFAULT_GENERIC_PATTERNS = [
        'click here', 'read more', 'learn more', 'here', 'link', 'this', 'more info',
        'more', 'details', 'info', 'see more', 'find out more', 'go', 'continue',
    ];

    /**
     * Get all non-external links pointing to a target element with non-empty anchor text.
     *
     * @return array<int, array{id: int, sourceElementId: int, anchorText: string, fieldHandle: string}>
     */
    public function getAnchorsForTarget(int $targetElementId, int $siteId): array
    {
        $records = LinkRecord::find()
            ->where([
                'targetElementId' => $targetElementId,
                'sourceSiteId' => $siteId,
                'isExternal' => false,
            ])
            ->andWhere(['not', ['anchorText' => null]])
            ->andWhere(['not', ['anchorText' => '']])
            ->all();

        $result = [];
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            $result[] = [
                'id' => (int) $record->id,
                'sourceElementId' => (int) $record->sourceElementId,
                'anchorText' => (string) $record->anchorText,
                'fieldHandle' => $record->fieldHandle,
            ];
        }

        return $result;
    }

    /**
     * Find all non-external links with anchor text that matches generic patterns.
     *
     * @param string[] $patterns
     * @return array<int, array{sourceElementId: int, targetElementId: int|null, anchorText: string, fieldHandle: string}>
     */
    public function findGenericAnchors(int $siteId, array $patterns): array
    {
        $records = LinkRecord::find()
            ->where([
                'sourceSiteId' => $siteId,
                'isExternal' => false,
            ])
            ->andWhere(['not', ['anchorText' => null]])
            ->andWhere(['not', ['anchorText' => '']])
            ->all();

        $result = [];
        foreach ($records as $record) {
            /** @var LinkRecord $record */
            if ($this->isGeneric((string) $record->anchorText, $patterns)) {
                $result[] = [
                    'sourceElementId' => (int) $record->sourceElementId,
                    'targetElementId' => $record->targetElementId !== null ? (int) $record->targetElementId : null,
                    'anchorText' => (string) $record->anchorText,
                    'fieldHandle' => $record->fieldHandle,
                ];
            }
        }

        return $result;
    }

    /**
     * Check whether an anchor text matches any of the given generic patterns (case-insensitive).
     * Empty string always returns false.
     *
     * @param string[] $patterns
     */
    public function isGeneric(string $anchorText, array $patterns): bool
    {
        if ($anchorText === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (strcasecmp($anchorText, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }
}
