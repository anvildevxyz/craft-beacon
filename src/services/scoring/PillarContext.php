<?php

namespace anvildev\beacon\services\scoring;

use craft\base\ElementInterface;

/**
 * Compute-time context handed to every pillar by
 * {@see \anvildev\beacon\services\GeoScoreService}. Carries the target
 * element + site plus a lazy accessor for the parsed content AST.
 *
 * AST construction is deferred until the first pillar that needs it calls
 * {@see self::ast()}. Cheap pillars (Freshness, Entity completeness) never
 * trigger the walk; structural pillars (Claim-based headings, Chunkability,
 * Fact density, Outbound citations) share the result of a single walk for
 * the duration of one `compute()` call.
 */
final class PillarContext
{
    /** @var list<ContentNode>|null */
    private ?array $ast = null;

    public function __construct(
        public readonly ElementInterface $element,
        public readonly int $siteId,
        private readonly ?ContentWalker $walker = null,
    ) {
    }

    /**
     * @return list<ContentNode>
     */
    public function ast(): array
    {
        return $this->ast ??= ($this->walker ?? new ContentWalker())->walk($this->element, $this->siteId);
    }
}
