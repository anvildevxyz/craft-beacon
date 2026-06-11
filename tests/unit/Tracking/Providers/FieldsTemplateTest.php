<?php

namespace anvildev\beacon\tests\unit\Tracking\Providers;

use anvildev\beacon\tracking\providers\CustomScriptProvider;
use anvildev\beacon\tracking\providers\FacebookPixelProvider;
use anvildev\beacon\tracking\providers\GA4Provider;
use anvildev\beacon\tracking\providers\GTMProvider;
use anvildev\beacon\tracking\providers\MatomoProvider;
use anvildev\beacon\tracking\TrackingScriptProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards the tracking-script edit form against the "provider has no field
 * template" crash: the form includes `provider.getFieldsTemplate()`, so every
 * built-in provider must point at a template that actually exists on disk.
 */
final class FieldsTemplateTest extends TestCase
{
    /** @return iterable<string, array{TrackingScriptProviderInterface}> */
    public static function builtInProviders(): iterable
    {
        yield 'ga4' => [new GA4Provider()];
        yield 'gtm' => [new GTMProvider()];
        yield 'facebook_pixel' => [new FacebookPixelProvider()];
        yield 'matomo' => [new MatomoProvider()];
        yield 'custom' => [new CustomScriptProvider()];
    }

    /**
     * @dataProvider builtInProviders
     */
    public function testFieldsTemplateResolvesToExistingFile(TrackingScriptProviderInterface $provider): void
    {
        $template = $provider->getFieldsTemplate();
        $this->assertNotNull($template, 'Built-in providers must declare a fields template.');

        // Path is namespaced `beacon/...`; the file lives under src/templates/...
        $relative = preg_replace('#^beacon/#', '', (string) $template) . '.twig';
        $path = dirname(__DIR__, 4) . '/src/templates/' . $relative;
        $this->assertFileExists($path, "Fields template for '{$provider->getHandle()}' is missing: {$path}");
    }

    public function testFieldsTemplateIsHandleKeyed(): void
    {
        $this->assertSame(
            'beacon/tracking/_provider-fields/ga4',
            (new GA4Provider())->getFieldsTemplate(),
        );
    }
}
