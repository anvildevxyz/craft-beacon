<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * Exercises the pure, Craft-app-free logic on {@see BeaconVariable}: meta-tag
 * map building, override application, HTML rendering, tag counting, pagination
 * meta rewriting, and the static URL helpers. None of these touch Craft::$app.
 */
class BeaconVariablePureLogicTest extends TestCase
{
    public function testIsHomepageEntryUriDetectsRootVariants(): void
    {
        $this->assertTrue(BeaconVariable::isHomepageEntryUri(''));
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('/'));
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('///'));
        $this->assertTrue(BeaconVariable::isHomepageEntryUri('__home__'));
        $this->assertFalse(BeaconVariable::isHomepageEntryUri('blog'));
        $this->assertFalse(BeaconVariable::isHomepageEntryUri('/blog/post'));
    }

    public function testPageAlternatesLeavesFirstPageUntouched(): void
    {
        $alternates = [['hreflang' => 'en', 'href' => 'https://example.test/blog']];
        $this->assertSame($alternates, BeaconVariable::pageAlternates($alternates, 'page', 1));
        $this->assertSame($alternates, BeaconVariable::pageAlternates($alternates, 'page', 0));
    }

    public function testPageAlternatesRewritesHrefForLaterPages(): void
    {
        $alternates = [
            ['hreflang' => 'en', 'href' => 'https://example.test/blog'],
            ['hreflang' => 'de', 'href' => 'https://example.test/de/blog'],
        ];
        $result = BeaconVariable::pageAlternates($alternates, 'page', 3);
        $this->assertSame('en', $result[0]['hreflang']);
        $this->assertStringContainsString('page=3', $result[0]['href']);
        $this->assertStringContainsString('page=3', $result[1]['href']);
    }

    public function testSetTagPicksPropertyAttributeForOpenGraphAndArticle(): void
    {
        $variable = new BeaconVariable();
        $variable->setTag('og:title', 'Hello');
        $variable->setTag('article:author', 'Jane');
        $variable->setTag('description', 'A page');
        $variable->setTag('  ', 'ignored');

        $overrides = $this->getProperty($variable, 'tagOverrides');
        $this->assertSame('property', $overrides['og:title']['attr']);
        $this->assertSame('property', $overrides['article:author']['attr']);
        $this->assertSame('name', $overrides['description']['attr']);
        $this->assertArrayNotHasKey('', $overrides, 'Blank tag names are ignored');
    }

    public function testRemoveTagMarksOverrideAsNull(): void
    {
        $variable = new BeaconVariable();
        $variable->removeTag('description');
        $overrides = $this->getProperty($variable, 'tagOverrides');
        $this->assertNull($overrides['description']);
    }

    public function testBuildMetaTagMapEmitsCoreSocialAndArticleTags(): void
    {
        $meta = new SeoMeta();
        $meta->description = 'A description';
        $meta->robots = ['index', 'follow'];
        $meta->openGraph = ['title' => 'OG', 'type' => 'article', 'url' => 'https://example.test/a', 'image' => ''];
        $meta->twitter = ['card' => 'summary', 'site' => '@beacon'];
        $meta->articleTimes = ['publishedTime' => '2026-05-01T00:00:00+00:00'];

        $tags = $this->invoke(new BeaconVariable(), 'buildMetaTagMap', [$meta]);

        $this->assertSame('index, follow', $tags['robots']['content']);
        $this->assertSame('property', $tags['og:title']['attr']);
        $this->assertSame('OG', $tags['og:title']['content']);
        $this->assertArrayNotHasKey('og:image', $tags, 'Empty-string values are skipped');
        $this->assertSame('name', $tags['twitter:card']['attr']);
        $this->assertSame('2026-05-01T00:00:00+00:00', $tags['article:published_time']['content']);
    }

    public function testBuildMetaTagMapSkipsArticleTimesWhenTypeNotArticle(): void
    {
        $meta = new SeoMeta();
        $meta->openGraph = ['type' => 'website'];
        $meta->articleTimes = ['publishedTime' => '2026-05-01T00:00:00+00:00'];

        $tags = $this->invoke(new BeaconVariable(), 'buildMetaTagMap', [$meta]);

        $this->assertArrayNotHasKey('article:published_time', $tags);
    }

    public function testApplyTagOverridesAddsAndRemovesTags(): void
    {
        $variable = new BeaconVariable();
        $variable->setTag('og:title', 'Override');
        $variable->removeTag('description');

        $tags = $this->invoke($variable, 'applyTagOverrides', [[
            'description' => ['attr' => 'name', 'name' => 'description', 'content' => 'Original'],
            'og:title' => ['attr' => 'property', 'name' => 'og:title', 'content' => 'Original'],
        ]]);

        $this->assertArrayNotHasKey('description', $tags, 'removeTag drops the entry');
        $this->assertSame('Override', $tags['og:title']['content']);
    }

    public function testRenderMetaTagMapEscapesAndSkipsEmptyContent(): void
    {
        $html = $this->invoke(new BeaconVariable(), 'renderMetaTagMap', [[
            ['attr' => 'name', 'name' => 'description', 'content' => 'Tom & "Jerry"'],
            ['attr' => 'property', 'name' => 'og:title', 'content' => ''],
        ]]);

        $this->assertStringContainsString('name="description" content="Tom &amp; &quot;Jerry&quot;"', $html);
        $this->assertStringNotContainsString('og:title', $html, 'Empty content is skipped');
    }

    public function testCountRenderedTagsCountsEverythingThatRenders(): void
    {
        $meta = new SeoMeta();
        $meta->title = 'Title';
        $meta->description = 'Desc';
        $meta->canonical = 'https://example.test/a';
        $meta->robots = ['index'];
        $meta->alternates = [['hreflang' => 'en', 'href' => 'x'], ['hreflang' => 'de', 'href' => 'y']];
        $meta->openGraph = ['title' => 'OG', 'type' => 'article', 'empty' => ''];
        $meta->twitter = ['card' => 'summary'];
        $meta->articleTimes = ['publishedTime' => '2026-05-01T00:00:00+00:00', 'modifiedTime' => '2026-05-02T00:00:00+00:00'];

        // title + description + canonical + robots = 4
        // alternates 2 + og(title,type) 2 + twitter(card) 1 = 5
        // articleTimes 2 = 2  → total 11
        $this->assertSame(11, $this->invoke(new BeaconVariable(), 'countRenderedTags', [$meta]));
    }

    public function testApplyOverridesAppliesValidValuesAndSkipsUnknownKeys(): void
    {
        $meta = new SeoMeta();
        $this->invoke(new BeaconVariable(), 'applyOverrides', [$meta, [
            'title' => 'New Title',
            'notARealProperty' => 'ignored',
        ]]);

        $this->assertSame('New Title', $meta->title);
    }

    public function testApplyPaginationToMetaReturnsEarlyWithoutState(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->canonical = 'https://example.test/blog';

        $this->invoke($variable, 'applyPaginationToMeta', [$meta]);

        $this->assertSame([], $meta->paginationLinkTags);
        $this->assertSame('https://example.test/blog', $meta->canonical);
    }

    public function testApplyPaginationToMetaBuildsPrevNextAndSelfCanonical(): void
    {
        $variable = new BeaconVariable();
        $variable->setPagination([
            'page' => 2,
            'pageCount' => 4,
            'baseUrl' => 'https://example.test/blog',
            'canonicalMode' => 'self',
        ]);
        $meta = new SeoMeta();

        $this->invoke($variable, 'applyPaginationToMeta', [$meta]);

        $this->assertStringContainsString('page=2', (string) $meta->canonical);
        $rels = array_column($meta->paginationLinkTags, 'rel');
        $this->assertContains('prev', $rels);
        $this->assertContains('next', $rels);
    }

    public function testApplyPaginationToMetaFirstPageCanonicalKeepsBaseUrl(): void
    {
        $variable = new BeaconVariable();
        $variable->setPagination([
            'page' => 3,
            'pageCount' => 5,
            'baseUrl' => 'https://example.test/blog',
            'canonicalMode' => 'firstPageCanonical',
        ]);
        $meta = new SeoMeta();

        $this->invoke($variable, 'applyPaginationToMeta', [$meta]);

        $this->assertSame('https://example.test/blog', $meta->canonical);
        $rels = array_column($meta->paginationLinkTags, 'rel');
        $this->assertContains('prev', $rels);
        $this->assertContains('next', $rels);
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function getProperty(object $obj, string $name): mixed
    {
        $prop = (new ReflectionObject($obj))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }
}
