<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\controllers\GeoExportController;
use anvildev\beacon\fields\BeaconSeoField;
use anvildev\beacon\helpers\Http;
use anvildev\beacon\models\LlmsSettings;
use anvildev\beacon\Plugin;
use anvildev\beacon\variables\BeaconVariable;
use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use DateTime;
use ReflectionObject;

/**
 * Issue #48 — `rel="describedby"` discovery alongside the existing
 * `rel="alternate"; type="text/markdown"` relation: an in-page `<link>` tag,
 * a `Link:` response header on the HTML entry page, and a `Link:` header on
 * the Markdown export response — all suppressed when llms.txt is disabled
 * for the site.
 *
 * @group requires-craft
 */
class LlmsDescribedByTest extends TestCase
{
    public function testHeadEmitsDescribedByTagAndLinkHeaderWhenLlmsEnabled(): void
    {
        $this->withGeoMarkdownAndLlmsEnabled(true, function(Entry $entry): void {
            Craft::$app->getUrlManager()->setMatchedElement($entry);

            $html = (string) $this->invokeRenderHead(new BeaconVariable());

            $this->assertMatchesRegularExpression(
                '#<link rel="alternate" type="text/markdown" href="[^"]+">\s*'
                . '<link rel="describedby" href="[^"]*/llms\.txt">#',
                $html,
            );

            $link = (string) Http::response()->getHeaders()->get('Link');
            $this->assertStringContainsString('rel="alternate"; type="text/markdown"', $link);
            $this->assertStringContainsString('rel="describedby"', $link);
        });
    }

    public function testHeadOmitsDescribedByWhenLlmsDisabledForSite(): void
    {
        $this->withGeoMarkdownAndLlmsEnabled(false, function(Entry $entry): void {
            Craft::$app->getUrlManager()->setMatchedElement($entry);

            $html = (string) $this->invokeRenderHead(new BeaconVariable());

            $this->assertStringContainsString('<link rel="alternate" type="text/markdown"', $html);
            $this->assertStringNotContainsString('rel="describedby"', $html);

            $link = (string) Http::response()->getHeaders()->get('Link');
            $this->assertStringContainsString('rel="alternate"; type="text/markdown"', $link);
            $this->assertStringNotContainsString('rel="describedby"', $link);
        });
    }

    public function testGeoExportResponseCarriesDescribedByHeaderWhenLlmsEnabled(): void
    {
        $this->withGeoMarkdownAndLlmsEnabled(true, function(Entry $entry): void {
            $controller = new GeoExportController('geo-export', Craft::$app);
            $response = $controller->actionIndex((int) $entry->id);

            $link = (string) $response->headers->get('Link');
            $this->assertStringContainsString('rel="canonical"', $link);
            $this->assertStringContainsString('rel="describedby"', $link);
        });
    }

    public function testGeoExportResponseOmitsDescribedByHeaderWhenLlmsDisabled(): void
    {
        $this->withGeoMarkdownAndLlmsEnabled(false, function(Entry $entry): void {
            $controller = new GeoExportController('geo-export', Craft::$app);
            $response = $controller->actionIndex((int) $entry->id);

            $link = (string) $response->headers->get('Link');
            $this->assertStringContainsString('rel="canonical"', $link);
            $this->assertStringNotContainsString('rel="describedby"', $link);
        });
    }

    /**
     * Enables GEO Markdown + the `.md` suffix route, sets llms.txt enablement
     * for the current site to `$llmsEnabled`, creates a live entry, runs
     * `$callback`, then restores every setting this helper touched. Plugin
     * settings and the per-site settings cache are not covered by the suite's
     * DB-transaction rollback (see {@see self::clearSiteSettingsCache()}), so
     * both are restored explicitly.
     *
     * @param callable(Entry): void $callback
     */
    private function withGeoMarkdownAndLlmsEnabled(bool $llmsEnabled, callable $callback): void
    {
        $plugin = Plugin::getInstance();
        $previousSettings = clone $plugin->settings->get();
        $site = Craft::$app->getSites()->getPrimarySite();
        $previousLlms = $plugin->siteSettings->getLlms($site->id);
        // MetaResolverService force-noindexes everything outside Production
        // (see EnvironmentMapper), which would otherwise suppress the
        // markdown-alternate tag this test is asserting on.
        $previousEnv = Craft::$app->env;

        try {
            $settings = clone $previousSettings;
            $settings->geoMarkdownEnabled = true;
            $settings->geoMarkdownMdSuffixEnabled = true;
            $plugin->settings->save($settings);

            $this->saveLlmsEnabled($site->id, $previousLlms, $llmsEnabled);
            Craft::$app->env = 'production';

            $entry = $this->createLiveEntryWithSeo([]);
            $callback($entry);
        } finally {
            Craft::$app->env = $previousEnv;
            $plugin->settings->save($previousSettings);
            $this->saveLlmsEnabled($site->id, $previousLlms, $previousLlms->enabled);
        }
    }

    private function saveLlmsEnabled(int $siteId, LlmsSettings $base, bool $enabled): void
    {
        Plugin::getInstance()->siteSettings->saveLlms(new LlmsSettings(
            siteId: $siteId,
            enabled: $enabled,
            summary: $base->summary,
            siteNameOverride: $base->siteNameOverride,
            sections: $base->sections,
            policyUrl: $base->policyUrl,
            licenseUrl: $base->licenseUrl,
            contactEmail: $base->contactEmail,
            preferredAttribution: $base->preferredAttribution,
            fullBody: $base->fullBody,
            llmsFullTokenBudget: $base->llmsFullTokenBudget,
        ));
        $this->clearSiteSettingsCache();
    }

    /**
     * Site settings sit behind a per-request memo and a tag-invalidated
     * cross-request cache, neither of which the suite's DB-transaction
     * rollback clears — so a value saved earlier in the same test run can
     * outlive the row it came from. Clear both after every write.
     */
    private function clearSiteSettingsCache(): void
    {
        Craft::$app->getCache()->flush();

        $service = Plugin::getInstance()->siteSettings;
        $property = (new ReflectionObject($service))->getProperty('cache');
        $property->setAccessible(true);
        $property->setValue($service, []);
    }

    /**
     * @param array<string,mixed> $seoValue
     */
    private function createLiveEntryWithSeo(array $seoValue): Entry
    {
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle('seo');
        if (!$field instanceof BeaconSeoField) {
            $field = new BeaconSeoField();
            $field->name = 'SEO';
            $field->handle = 'seo';
            $this->assertTrue($fields->saveField($field), 'save field: ' . json_encode($field->getErrors()));
        }

        $entries = Craft::$app->getEntries();
        $entryType = new EntryType();
        $entryType->name = 'Describedby Page';
        $entryType->handle = 'describedbyPage';
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    ['type' => CustomField::class, 'fieldUid' => $field->uid],
                ],
            ],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue($entries->saveEntryType($entryType), 'save entry type: ' . json_encode($entryType->getErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = 'Describedby Pages';
        $section->handle = 'describedbyPages';
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => 'describedby-pages/{slug}',
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue($entries->saveSection($section), 'save section: ' . json_encode($section->getErrors()));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $site->id;
        $entry->title = 'Describedby Entry';
        $entry->slug = 'describedby-' . uniqid();
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');
        $entry->setFieldValue('seo', $seoValue);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry), 'save entry: ' . json_encode($entry->getErrors()));

        return $entry;
    }

    private function invokeRenderHead(BeaconVariable $variable): \Twig\Markup
    {
        $ref = new ReflectionObject($variable);
        $method = $ref->getMethod('renderHead');
        $method->setAccessible(true);
        return $method->invoke($variable);
    }
}
