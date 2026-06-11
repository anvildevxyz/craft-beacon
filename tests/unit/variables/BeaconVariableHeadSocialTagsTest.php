<?php

namespace anvildev\beacon\tests\unit\variables;

use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\variables\BeaconVariable;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class BeaconVariableHeadSocialTagsTest extends TestCase
{
    public function testHeadRendersOpenGraphAndTwitterTagsWhenMetaHasValues(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Page Title';
        $meta->description = 'Description';
        $meta->canonical = 'https://example.test/post';
        $meta->openGraph = [
            'title' => 'OG Title',
            'description' => 'OG Description',
            'image' => 'https://example.test/og.jpg',
            'type' => 'article',
            'siteName' => 'Beacon Site',
            'url' => 'https://example.test/post',
        ];
        $meta->twitter = [
            'card' => 'summary_large_image',
            'title' => 'Tw Title',
            'description' => 'Tw Description',
            'image' => 'https://example.test/tw.jpg',
            'site' => '@beacon',
        ];

        $this->setProperty($variable, 'cachedMeta', $meta);
        $this->setProperty($variable, 'cachedSchemas', []);

        $html = (string) $variable->head();

        $this->assertStringContainsString('property="og:title" content="OG Title"', $html);
        $this->assertStringContainsString('property="og:url" content="https://example.test/post"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('name="twitter:site" content="@beacon"', $html);
    }

    public function testHeadRendersArticleCompanionMetaWhenOgTypeArticle(): void
    {
        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Article';
        $meta->description = 'Desc';
        $meta->openGraph = [
            'title' => 'Article',
            'description' => 'Desc',
            'type' => 'article',
            'siteName' => 'Site',
            'url' => 'https://example.test/a',
        ];
        $meta->twitter = ['card' => 'summary', 'title' => 'Article', 'description' => 'Desc', 'image' => null, 'site' => null];
        $meta->articleTimes = [
            'publishedTime' => '2026-05-01T12:00:00+00:00',
            'modifiedTime' => '2026-05-02T15:30:00+00:00',
        ];

        $this->setProperty($variable, 'cachedMeta', $meta);
        $this->setProperty($variable, 'cachedSchemas', []);

        $html = (string) $variable->head();

        $this->assertStringContainsString('property="article:published_time"', $html);
        $this->assertStringContainsString('property="article:modified_time"', $html);
    }

    private function setProperty(object $obj, string $name, mixed $value): void
    {
        $ref = new ReflectionObject($obj);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }
}
