<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\events\DefineMetaTagsEvent;
use anvildev\beacon\models\SeoMeta;
use anvildev\beacon\Plugin;
use anvildev\beacon\variables\BeaconVariable;
use craft\test\TestCase;
use ReflectionObject;
use yii\base\Event;

class MetaTagEventHookTest extends TestCase
{
    public function testDefineMetaTagsEventCanMutateHeadOutput(): void
    {
        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            $this->markTestSkipped('Requires initialized Craft web app/request context.');
        }

        $variable = new BeaconVariable();
        $meta = new SeoMeta();
        $meta->title = 'Original Title';
        $meta->description = 'Original Description';
        $meta->twitter = ['card' => 'summary', 'title' => 'Tw', 'description' => 'D', 'image' => null, 'site' => null];
        $meta->openGraph = ['title' => 'OG', 'description' => 'D', 'type' => 'website', 'siteName' => 'Site', 'url' => 'https://example.test'];

        $this->setPrivate($variable, 'cachedMeta', $meta);
        $this->setPrivate($variable, 'cachedSchemas', []);

        $handler = static function(DefineMetaTagsEvent $event): void {
            $event->tags['description'] = [
                'attr' => 'name',
                'name' => 'description',
                'content' => 'Listener Description',
            ];
            $event->tags['robots'] = [
                'attr' => 'name',
                'name' => 'robots',
                'content' => 'noindex, nofollow',
            ];
        };
        Event::on(Plugin::class, Plugin::EVENT_DEFINE_META_TAGS, $handler);

        try {
            $html = (string) $variable->head();
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_DEFINE_META_TAGS, $handler);
        }

        $this->assertStringContainsString('name="description" content="Listener Description"', $html);
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        $this->assertStringNotContainsString('name="description" content="Original Description"', $html);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionObject($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
