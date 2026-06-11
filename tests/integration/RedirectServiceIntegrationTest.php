<?php

namespace anvildev\beacon\tests\integration;

use anvildev\beacon\elements\RedirectElement;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\Plugin;
use anvildev\beacon\records\RedirectRecord;
use anvildev\beacon\services\RedirectService;
use Craft;
use craft\enums\PropagationMethod;
use craft\test\TestCase;

/**
 * Fixtures must save {@see RedirectElement} instances — not bare records — so
 * propagation, status, and sortOrder stay consistent.
 *
 * @group requires-craft
 */
class RedirectServiceIntegrationTest extends TestCase
{
    public function testFindRedirectResolvesExactMatch(): void
    {
        $site = $this->siteId();
        $this->makeRedirect($site, '/old-page', '/new-page', ['statusCode' => 301]);

        $result = $this->service()->findRedirect($site, '/old-page');

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('/new-page', $result->resolvedTarget);
        $this->assertSame(301, $result->statusCode);
    }

    public function testFindRedirectReturnsNullForNoMatch(): void
    {
        $site = $this->siteId();
        $this->makeRedirect($site, '/old-page', '/new-page');

        $this->assertNull($this->service()->findRedirect($site, '/nothing-here'));
    }

    public function testDisabledRedirectIsNotMatched(): void
    {
        $site = $this->siteId();
        $this->makeRedirect($site, '/disabled', '/target', ['enabled' => false]);

        $this->assertNull($this->service()->findRedirect($site, '/disabled'));
    }

    public function testAllSitesRedirectMatchesFromAnySite(): void
    {
        $site = $this->siteId();
        $this->makeRedirect(null, '/global', '/global-target');

        $result = $this->service()->findRedirect($site, '/global');

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('/global-target', $result->resolvedTarget);
    }

    public function testFindRedirectAppliesGlobCapture(): void
    {
        $site = $this->siteId();
        $this->makeRedirect($site, '/blog/*', '/news/$1', ['type' => RedirectType::Glob->value]);

        $result = $this->service()->findRedirect($site, '/blog/hello-world');

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('/news/hello-world', $result->resolvedTarget);
    }

    public function testCreateAutoRedirectIsIdempotent(): void
    {
        $site = $this->siteId();
        $this->service()->createAutoRedirect($site, '/auto', '/auto-target');
        // A second call for the same source must NOT overwrite or duplicate.
        $this->service()->createAutoRedirect($site, '/auto', '/different-target');

        $count = RedirectRecord::find()->where(['sourceUri' => '/auto'])->count();
        $this->assertSame(1, (int) $count);
        $this->assertSame('/auto-target', $this->service()->findRedirect($site, '/auto')?->resolvedTarget);
    }

    public function testRecordHitIncrementsCounterAndStampsLastHit(): void
    {
        $site = $this->siteId();
        $redirect = $this->makeRedirect($site, '/hit-me', '/dest');

        $this->service()->recordHit((int) $redirect->id);
        $this->service()->recordHit((int) $redirect->id);

        $fresh = RedirectRecord::findOne($redirect->id);
        $this->assertSame(2, (int) $fresh->hits);
        $this->assertNotNull($fresh->lastHit);
    }

    public function testCountForSiteCountsOnlyThatSite(): void
    {
        $site = $this->siteId();
        $this->makeRedirect($site, '/a', '/x');
        $this->makeRedirect($site, '/b', '/y');

        $this->assertSame(2, $this->service()->countForSite($site));
    }

    public function testNextSortOrderIsMaxPlusOne(): void
    {
        $site = $this->siteId();
        // sortOrder is managed by the precedence structure (0-based, in
        // insertion order), so nextSortOrder() is always currentMax + 1.
        $this->assertSame(0, $this->service()->nextSortOrder($site));

        $this->makeRedirect($site, '/a', '/x');
        $this->assertSame(1, $this->service()->nextSortOrder($site));

        $this->makeRedirect($site, '/b', '/y');
        $this->assertSame(2, $this->service()->nextSortOrder($site));
    }

    private function service(): RedirectService
    {
        return Plugin::getInstance()->redirects;
    }

    private function siteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * @param array{statusCode?:int, type?:string, enabled?:bool, qsMode?:string} $opts
     */
    private function makeRedirect(?int $siteId, string $source, string $target, array $opts = []): RedirectElement
    {
        $element = new RedirectElement();
        $element->propagationMethod = $siteId === null ? PropagationMethod::All : PropagationMethod::None;
        $element->siteId = $siteId ?? $this->siteId();
        $element->sourceUri = $source;
        $element->targetUri = $target;
        $element->statusCode = $opts['statusCode'] ?? 301;
        $element->type = $opts['type'] ?? RedirectType::Exact->value;
        $element->enabled = $opts['enabled'] ?? true;
        $element->queryStringMode = $opts['qsMode'] ?? 'ignore';
        $element->source = 'manual';

        $saved = Craft::$app->getElements()->saveElement($element);
        $this->assertTrue($saved, 'save redirect: ' . json_encode($element->getErrors()));

        return $element;
    }
}
