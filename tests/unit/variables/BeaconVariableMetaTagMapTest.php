<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

/**
 * Direct coverage of the meta-tag map builder/renderer — the core <head>
 * algorithm isolated from resolveMeta() and Craft bootstrap.
 */
class BeaconVariableMetaTagMapTest extends TestCase
{
    public function testBuildMetaTagMapSkipsEmptyScalars(): void
    {
        $meta = new SeoMeta();
        $meta->description = '';
        $meta->robots = [];
        $meta->openGraph = ['title' => '', 'type' => 'website'];
        $meta->twitter = ['card' => 'summary'];

        $tags = $this->invoke($this->variable(), 'buildMetaTagMap', [$meta]);

        $this->assertArrayNotHasKey('description', $tags);
        $this->assertArrayNotHasKey('robots', $tags);
        $this->assertArrayNotHasKey('og:title', $tags);
        $this->assertSame('summary', $tags['twitter:card']['content']);
    }

    public function testBuildMetaTagMapIncludesArticleCompanionMeta(): void
    {
        $meta = new SeoMeta();
        $meta->description = 'Desc';
        $meta->openGraph = [
            'type' => 'article',
            'title' => 'Article',
        ];
        $meta->twitter = ['card' => 'summary'];
        $meta->articleTimes = [
            'publishedTime' => '2026-01-01T00:00:00Z',
            'modifiedTime' => '2026-01-02T00:00:00Z',
        ];

        $tags = $this->invoke($this->variable(), 'buildMetaTagMap', [$meta]);

        $this->assertSame('property', $tags['article:published_time']['attr']);
        $this->assertSame('2026-01-01T00:00:00Z', $tags['article:published_time']['content']);
        $this->assertSame('2026-01-02T00:00:00Z', $tags['article:modified_time']['content']);
    }

    public function testApplyTagOverridesReplacesAddsAndRemoves(): void
    {
        $variable = $this->variable();
        $this->setProperty($variable, 'tagOverrides', [
            'og:title' => ['attr' => 'property', 'name' => 'og:title', 'content' => 'Override'],
            'twitter:card' => null,
        ]);

        $base = [
            'og:title' => ['attr' => 'property', 'name' => 'og:title', 'content' => 'Original'],
            'twitter:card' => ['attr' => 'name', 'name' => 'twitter:card', 'content' => 'summary'],
        ];

        $tags = $this->invoke($variable, 'applyTagOverrides', [$base]);

        $this->assertSame('Override', $tags['og:title']['content']);
        $this->assertArrayNotHasKey('twitter:card', $tags);
    }

    public function testRenderMetaTagMapSkipsEmptyContentAndEscapesHtml(): void
    {
        $html = $this->invoke($this->variable(), 'renderMetaTagMap', [[
            ['attr' => 'name', 'name' => 'description', 'content' => 'Hello & "world"'],
            ['attr' => 'name', 'name' => 'robots', 'content' => ''],
            ['attr' => 'property', 'name' => 'og:title', 'content' => '<script>'],
        ]]);

        $this->assertStringContainsString('content="Hello &amp; &quot;world&quot;"', $html);
        $this->assertStringContainsString('content="&lt;script&gt;"', $html);
        $this->assertStringNotContainsString('name="robots"', $html);
        $this->assertSame(2, substr_count($html, '<meta '));
    }

    public function testCountRenderedTagsCountsCoreAndSocialFields(): void
    {
        $meta = new SeoMeta();
        $meta->title = 'Title';
        $meta->description = 'Desc';
        $meta->canonical = 'https://example.test/page';
        $meta->robots = ['index', 'follow'];
        $meta->alternates = [['hreflang' => 'de', 'href' => 'https://example.test/de']];
        $meta->paginationLinkTags = [['rel' => 'next', 'href' => 'https://example.test/page/2']];
        $meta->openGraph = ['title' => 'OG', 'type' => 'article', 'url' => 'https://example.test/page'];
        $meta->twitter = ['card' => 'summary', 'title' => 'Tw'];
        $meta->articleTimes = [
            'publishedTime' => '2026-01-01T00:00:00Z',
            'modifiedTime' => '2026-01-02T00:00:00Z',
        ];

        $count = $this->invoke($this->variable(), 'countRenderedTags', [$meta]);

        // title + description + canonical + robots + alternates + pagination + 3 og + 2 twitter + 2 article times
        $this->assertSame(13, $count);
    }

    private function variable(): BeaconVariable
    {
        return new BeaconVariable();
    }

    /** @param array<int,mixed> $args */
    private function invoke(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionObject($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }
}
