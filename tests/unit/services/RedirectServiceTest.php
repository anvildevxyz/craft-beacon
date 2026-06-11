<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\events\AfterMatchRedirectEvent;
use anvildev\beacon\events\BeforeMatchRedirectEvent;
use anvildev\beacon\models\Redirect;
use anvildev\beacon\services\RedirectMatcher;
use anvildev\beacon\services\RedirectService;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

class RedirectServiceTest extends TestCase
{
    public function testStashAndPopOldUri(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $service->stashOldUri(42, '/old/path', 1);
        $this->assertSame('/old/path', $service->popOldUri(42, 1));
        $this->assertNull($service->popOldUri(42, 1));
    }

    public function testPopReturnsNullForUnknownEntry(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $this->assertNull($service->popOldUri(999, 1));
    }

    public function testResolveTarget(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $resolved = $service->resolveTarget('/blog/$1', ['$1' => 'foo']);
        $this->assertSame('/blog/foo', $resolved);
    }

    public function testResolveTargetMultipleCaptures(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $resolved = $service->resolveTarget('/news/$1/$2', ['$1' => '2024', '$2' => 'item']);
        $this->assertSame('/news/2024/item', $resolved);
    }

    public function testResolveTargetWithNoCaptures(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $this->assertSame('/static', $service->resolveTarget('/static', []));
    }

    public function testResolveTargetLeavesPlaceholdersAloneWhenNoCurrentSite(): void
    {
        // resolveTarget() is callable from outside `findRedirect()` (e.g. an
        // AfterMatch listener). When no site context is set, placeholders are
        // left literal — substitution requires the caller's site id.
        $service = new RedirectService(new RedirectMatcher());
        $this->assertSame('/{language}/promo', $service->resolveTarget('/{language}/promo', []));
    }

    public function testStashIsScopedByEntryAndSite(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $service->stashOldUri(42, '/en/old', 1);
        $service->stashOldUri(42, '/de/old', 2);
        $service->stashOldUri(42, '/fr/old', 3);

        $this->assertSame('/en/old', $service->popOldUri(42, 1));
        $this->assertSame('/de/old', $service->popOldUri(42, 2));
        $this->assertSame('/fr/old', $service->popOldUri(42, 3));
        $this->assertNull($service->popOldUri(42, 1));
    }

    // ---- events ----

    public function testBeforeMatchCanShortCircuitWithRedirect(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $stub = new Redirect(
            id: 99,
            siteId: 1,
            sourceUri: '/short',
            targetUri: '/circuit',
            statusCode: 302,
            type: RedirectType::Exact->value,
            resolvedTarget: '/circuit',
            queryStringMode: RedirectQueryStringMode::Ignore,
        );
        $service->on(RedirectService::EVENT_BEFORE_MATCH_REDIRECT, static function (BeforeMatchRedirectEvent $e) use ($stub): void {
            $e->isHandled = true;
            $e->redirect = $stub;
        });

        $result = $service->findRedirect(1, '/any/uri');
        $this->assertSame($stub, $result);
    }

    public function testBeforeMatchCanVetoRedirect(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $service->on(RedirectService::EVENT_BEFORE_MATCH_REDIRECT, static function (BeforeMatchRedirectEvent $e): void {
            $e->isHandled = true;
            $e->redirect = null;
        });

        $this->assertNull($service->findRedirect(1, '/any/uri'));
    }

    public function testAfterMatchCanCancelShortCircuitedRedirect(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $stub = new Redirect(
            id: 1,
            siteId: 1,
            sourceUri: '/x',
            targetUri: '/y',
            statusCode: 301,
            type: RedirectType::Exact->value,
            resolvedTarget: '/y',
        );
        $service->on(RedirectService::EVENT_BEFORE_MATCH_REDIRECT, static function (BeforeMatchRedirectEvent $e) use ($stub): void {
            $e->isHandled = true;
            $e->redirect = $stub;
        });
        $service->on(RedirectService::EVENT_AFTER_MATCH_REDIRECT, static function (AfterMatchRedirectEvent $e): void {
            $e->redirect = null;
        });

        $this->assertNull($service->findRedirect(1, '/x'));
    }

    public function testAfterMatchCanRewriteShortCircuitedRedirect(): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $original = new Redirect(
            id: 1,
            siteId: 1,
            sourceUri: '/x',
            targetUri: '/y',
            statusCode: 301,
            type: RedirectType::Exact->value,
            resolvedTarget: '/y',
        );
        $service->on(RedirectService::EVENT_BEFORE_MATCH_REDIRECT, static function (BeforeMatchRedirectEvent $e) use ($original): void {
            $e->isHandled = true;
            $e->redirect = $original;
        });
        $service->on(RedirectService::EVENT_AFTER_MATCH_REDIRECT, static function (AfterMatchRedirectEvent $e): void {
            $r = $e->redirect;
            if ($r === null) {
                return;
            }
            $e->redirect = new Redirect(
                id: $r->id,
                siteId: $r->siteId,
                sourceUri: $r->sourceUri,
                targetUri: $r->targetUri,
                statusCode: $r->statusCode,
                type: $r->type,
                resolvedTarget: $r->resolvedTarget . '?aff=beacon',
                queryStringMode: $r->queryStringMode,
            );
        });

        $result = $service->findRedirect(1, '/x');
        $this->assertNotNull($result);
        $this->assertSame('/y?aff=beacon', $result->resolvedTarget);
    }

    public function testDetectGraphIssuesFindsChain(): void
    {
        // /a → /b → /c : /b is itself a source, so /a is a chain head.
        $edges = [
            '/a' => ['id' => 1, 'target' => '/b'],
            '/b' => ['id' => 2, 'target' => '/c'],
        ];
        $issues = RedirectService::detectGraphIssues($edges);
        $kinds = array_column($issues, 'kind', 'sourceUri');
        $this->assertSame('chain', $kinds['/a'] ?? null);
        $this->assertArrayNotHasKey('/b', $kinds); // /c is not a source → /b is a clean single hop
    }

    public function testDetectGraphIssuesFindsDirectAndIndirectLoops(): void
    {
        $edges = [
            '/self' => ['id' => 1, 'target' => '/self'],   // A→A
            '/a' => ['id' => 2, 'target' => '/b'],         // A→B→A
            '/b' => ['id' => 3, 'target' => '/a'],
        ];
        $issues = RedirectService::detectGraphIssues($edges);
        $loops = array_values(array_filter($issues, static fn($i): bool => $i['kind'] === 'loop'));
        // Self-loop + the A↔B cycle reported once (deduped), not twice.
        $this->assertCount(2, $loops);
        $sources = array_column($loops, 'sourceUri');
        $this->assertContains('/self', $sources);
    }

    public function testDetectGraphIssuesIgnoresCleanRedirects(): void
    {
        // No target is also a source → no chains, no loops.
        $edges = [
            '/old-1' => ['id' => 1, 'target' => '/page-1'],
            '/old-2' => ['id' => 2, 'target' => '/page-2'],
        ];
        $this->assertSame([], RedirectService::detectGraphIssues($edges));
    }

    /**
     * @dataProvider normalizedRedirectPaths
     */
    public function testNormalizeRedirectPath(string $input, string $expected): void
    {
        $service = new RedirectService(new RedirectMatcher());
        $this->assertSame($expected, $this->invokePrivate($service, 'normalizeRedirectPath', [$input]));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function normalizedRedirectPaths(): array
    {
        return [
            'root-relative' => ['/foo/bar', '/foo/bar'],
            'no-leading-slash' => ['foo/bar', '/foo/bar'],
            'trailing-slash' => ['/foo/bar/', '/foo/bar'],
            'root-only' => ['/', '/'],
            'absolute-with-query' => ['https://site.test/b?x=1#frag', '/b'],
            'absolute-root' => ['https://site.test/', '/'],
        ];
    }

    /** @param array<int,mixed> $args */
    private function invokePrivate(object $obj, string $method, array $args): mixed
    {
        $ref = new ReflectionObject($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
