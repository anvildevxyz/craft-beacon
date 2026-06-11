<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\services\RedirectSuggestionEngine;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * The scoring algorithm is pure and DB-free, so we exercise it directly via
 * the private `score()` method. The DB-backed `uriListForSite` is covered by
 * the integration suite once Craft is bootstrapped.
 */
class RedirectSuggestionEngineTest extends TestCase
{
    public function testJaccardOverlapBeatsLexicalSimilarity(): void
    {
        // "/old-news/post" vs "/news/post"
        //   - jaccard: {news, post} ∩ {news, post} / union {old, news, post} = 2/3
        //   - similar_text: ~60%
        //   - blended: 0.6*0.667 + 0.4*0.6 = 0.4 + 0.24 = 0.64
        //
        // "/old-news/post" vs "/old-foo"
        //   - jaccard: {old} ∩ {old} / union {old, news, post, foo} = 1/4 = 0.25
        //   - similar_text: ~50%
        //   - blended: 0.6*0.25 + 0.4*0.5 = 0.15 + 0.20 = 0.35
        $engine = $this->seed(['/news/post', '/old-foo', '/blog/2024']);
        $result = $engine->suggestFor('/old-news/post', 1, 2);
        $this->assertSame(['/news/post', '/old-foo'], $result);
    }

    public function testExactlySameUriScoresHighest(): void
    {
        $engine = $this->seed(['/almost-here', '/about-us', '/contact']);
        // "/about-us" with typo "/abuot-us" — similar_text catches it even though
        // tokens don't overlap (`abuot` vs `about` are different tokens).
        $result = $engine->suggestFor('/abuot-us', 1, 1);
        $this->assertSame(['/about-us'], $result);
    }

    public function testEmptyTokensReturnEmpty(): void
    {
        $engine = $this->seed(['/news/post']);
        $this->assertSame([], $engine->suggestFor('/', 1));
        $this->assertSame([], $engine->suggestFor('///', 1));
    }

    public function testEmptyCandidateListReturnsEmpty(): void
    {
        $engine = $this->seed([]);
        $this->assertSame([], $engine->suggestFor('/anything', 1, 3));
    }

    public function testLimitRespected(): void
    {
        $engine = $this->seed(['/news/post', '/news/article', '/news/story', '/news/update']);
        $result = $engine->suggestFor('/news/post', 1, 2);
        $this->assertCount(2, $result);
    }

    public function testAllReturnedUrisStartWithSlash(): void
    {
        // Even if the cached list contains slash-less URIs, results are
        // normalized so the CP can render them as clickable paths.
        $engine = $this->seed(['news/post', 'about/team']);
        $result = $engine->suggestFor('/news/post', 1, 2);
        foreach ($result as $uri) {
            $this->assertStringStartsWith('/', $uri);
        }
    }

    public function testZeroScoreCandidatesAreExcluded(): void
    {
        // "/xyz" shares no tokens and no characters with "/abc-def", so it
        // scores exactly 0 and must never be suggested.
        $engine = $this->seed(['/xyz', '/abc-def']);
        $this->assertSame(['/abc-def'], $engine->suggestFor('/abc-def', 1, 5));
    }

    public function testCandidatesWithoutTokensAreExcluded(): void
    {
        // "/" and "/--" tokenise to nothing → score() short-circuits to 0.0.
        $engine = $this->seed(['/', '/--', '/news/post']);
        $this->assertSame(['/news/post'], $engine->suggestFor('/news/post', 1, 5));
    }

    /**
     * @param list<string> $uris
     */
    private function seed(array $uris): RedirectSuggestionEngine
    {
        $engine = new RedirectSuggestionEngine();
        $ref = new ReflectionObject($engine);
        $prop = $ref->getProperty('uriCache');
        $prop->setAccessible(true);
        $prop->setValue($engine, [1 => $uris]);
        return $engine;
    }
}
