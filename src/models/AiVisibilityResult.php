<?php

namespace anvildev\beacon\models;

/**
 * One answer-engine probe: the outcome of asking a single benchmark prompt of a
 * single engine. Stores an excerpt of the answer (never the full text) plus the
 * derived citation signals.
 */
class AiVisibilityResult
{
    /**
     * @param list<string> $matchedUrls site URLs the answer linked to
     * @param list<string> $competitorMentions competitor hosts named in the answer
     */
    public function __construct(
        public int $siteId = 0,
        public ?int $promptId = null,
        public string $promptText = '',
        public string $engine = '',
        public bool $cited = false,
        public bool $domainMentioned = false,
        public array $matchedUrls = [],
        public array $competitorMentions = [],
        public string $answerExcerpt = '',
        public ?string $runAt = null,
        public ?int $id = null,
    ) {
    }
}
