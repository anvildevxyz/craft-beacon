<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\services\scoring\HeadingClassifier;
use PHPUnit\Framework\TestCase;

class HeadingClassifierTest extends TestCase
{
    public function testClaimShapedEnglishHeadingsClassifyAsClaims(): void
    {
        $classifier = new HeadingClassifier('en');
        $this->assertTrue($classifier->isClaim('Composer plugins must run before PHP-FPM restarts'));
        $this->assertTrue($classifier->isClaim('Beacon ships GEO scoring in 3.1'));
        $this->assertTrue($classifier->isClaim('Async jobs reduce save-time latency'));
        $this->assertTrue($classifier->isClaim('Why claim-based headings work'));
    }

    public function testTopicShapedEnglishHeadingsDoNotClassifyAsClaims(): void
    {
        $classifier = new HeadingClassifier('en');
        $this->assertFalse($classifier->isClaim('Composer plugins'));
        $this->assertFalse($classifier->isClaim('GEO scoring'));
        $this->assertFalse($classifier->isClaim('Installation guide'));
    }

    public function testGermanHeadingsUseGermanVerbStems(): void
    {
        $classifier = new HeadingClassifier('de');
        $this->assertTrue($classifier->isClaim('Composer Plugins müssen vor PHP-FPM laufen'));
        $this->assertTrue($classifier->isClaim('Asynchrone Jobs reduzieren die Speicherlatenz'));
        // Same German heading should NOT classify under English rules —
        // proves the language switch actually swaps the stem set.
        $englishClassifier = new HeadingClassifier('en');
        $this->assertFalse($englishClassifier->isClaim('Composer Plugins müssen vor PHP-FPM laufen'));
    }

    public function testRegionalBcp47TagsResolveToPrimarySubtag(): void
    {
        // BCP-47 tags arrive as `de-CH`, `de-AT`, `en-US`, … from
        // Craft's Site model. The classifier must collapse them to the
        // primary subtag so a Swiss-German site gets the German stem set.
        $swissGerman = new HeadingClassifier('de-CH');
        $this->assertTrue($swissGerman->isClaim('Composer Plugins müssen vor PHP-FPM laufen'));
    }

    public function testShortHeadingsAreNotClaimsEvenWithVerb(): void
    {
        $classifier = new HeadingClassifier('en');
        // "It is broken" has a verb but is below the min-token threshold;
        // micro-headings don't carry self-contained answers.
        $this->assertFalse($classifier->isClaim('It is broken'));
    }

    public function testEmptyHeadingIsNotAClaim(): void
    {
        $classifier = new HeadingClassifier('en');
        $this->assertFalse($classifier->isClaim(''));
        $this->assertFalse($classifier->isClaim('   '));
    }

    public function testUnknownLanguageTagFallsBackToEnglish(): void
    {
        // The fallback is documented behaviour, not an accident — assert it
        // so a future contributor doesn't change it without thinking.
        $classifier = new HeadingClassifier('ja-JP');
        $this->assertTrue($classifier->isClaim('Composer plugins must run before PHP-FPM restarts'));
    }
}
