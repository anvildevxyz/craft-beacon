<?php

namespace anvildev\beacon\tests\unit\services;

use anvildev\beacon\enums\RedirectQueryStringMode;
use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\events\RegisterRedirectTypesEvent;
use anvildev\beacon\services\CustomRedirectMatcherInterface;
use anvildev\beacon\services\RedirectMatcher;
use PHPUnit\Framework\TestCase;

class RedirectMatcherTest extends TestCase
{
    private RedirectMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new RedirectMatcher();
    }

    public function testExactMatch(): void
    {
        $result = $this->matcher->matches('/old', RedirectType::Exact, '/old');
        $this->assertNotNull($result);
        $this->assertSame([], $result['captures']);
        $this->assertSame('', $result['query']);
    }

    public function testExactNoMatch(): void
    {
        $this->assertNull($this->matcher->matches('/old', RedirectType::Exact, '/different'));
    }

    public function testGlobSingleSegment(): void
    {
        $result = $this->matcher->matches('/blog/*', RedirectType::Glob, '/blog/foo');
        $this->assertNotNull($result);
        $this->assertSame(['$1' => 'foo'], $result['captures']);
    }

    public function testGlobDoesNotCrossSegments(): void
    {
        $this->assertNull($this->matcher->matches('/blog/*', RedirectType::Glob, '/blog/foo/bar'));
    }

    public function testGlobMultiSegment(): void
    {
        $result = $this->matcher->matches('/old/**', RedirectType::Glob, '/old/foo/bar/baz');
        $this->assertNotNull($result);
        $this->assertSame(['$1' => 'foo/bar/baz'], $result['captures']);
    }

    public function testGlobMultipleCaptures(): void
    {
        $result = $this->matcher->matches('/news/*/comments/*', RedirectType::Glob, '/news/2024/comments/42');
        $this->assertNotNull($result);
        $this->assertSame(['$1' => '2024', '$2' => '42'], $result['captures']);
    }

    public function testRegexMatch(): void
    {
        $result = $this->matcher->matches('^/legacy/(\d+)$', RedirectType::Regex, '/legacy/123');
        $this->assertNotNull($result);
        $this->assertSame(['$1' => '123'], $result['captures']);
    }

    public function testRegexNoMatch(): void
    {
        $this->assertNull($this->matcher->matches('^/legacy/(\d+)$', RedirectType::Regex, '/legacy/abc'));
    }

    public function testInvalidRegexReturnsNull(): void
    {
        $this->assertNull(@$this->matcher->matches('(unclosed', RedirectType::Regex, '/anything'));
    }

    // ---- query-string modes ----

    public function testIgnoreModeStripsQueryFromMatchSubject(): void
    {
        $result = $this->matcher->matches('/old', RedirectType::Exact, '/old?utm=fb', RedirectQueryStringMode::Ignore);
        $this->assertNotNull($result);
        $this->assertSame('', $result['query']);
    }

    public function testPreserveModeReturnsIncomingQuery(): void
    {
        $result = $this->matcher->matches('/old', RedirectType::Exact, '/old?utm=fb&k=v', RedirectQueryStringMode::Preserve);
        $this->assertNotNull($result);
        $this->assertSame('utm=fb&k=v', $result['query']);
    }

    public function testPreserveModeWithNoIncomingQuery(): void
    {
        $result = $this->matcher->matches('/old', RedirectType::Exact, '/old', RedirectQueryStringMode::Preserve);
        $this->assertNotNull($result);
        $this->assertSame('', $result['query']);
    }

    public function testMatchModeRequiresFullUriEquality(): void
    {
        $this->assertNotNull($this->matcher->matches('/sale?yr=2025', RedirectType::Exact, '/sale?yr=2025', RedirectQueryStringMode::Match));
        $this->assertNull($this->matcher->matches('/sale?yr=2025', RedirectType::Exact, '/sale?yr=2024', RedirectQueryStringMode::Match));
        $this->assertNull($this->matcher->matches('/sale?yr=2025', RedirectType::Exact, '/sale', RedirectQueryStringMode::Match));
    }

    public function testFragmentIsStrippedFromAllModes(): void
    {
        $this->assertNotNull($this->matcher->matches('/old', RedirectType::Exact, '/old#section', RedirectQueryStringMode::Ignore));
        $this->assertNotNull($this->matcher->matches('/old', RedirectType::Exact, '/old?x=1#section', RedirectQueryStringMode::Preserve));
    }

    // ---- custom type registration ----

    public function testMatchRuleDispatchesToBuiltinTypes(): void
    {
        $result = $this->matcher->matchRule('exact', '/old', '/old');
        $this->assertNotNull($result);
        $this->assertSame([], $result['captures']);
    }

    public function testMatchRuleFallsThroughToCustomMatcher(): void
    {
        $matcher = new RedirectMatcher();
        $matcher->on(RedirectMatcher::EVENT_REGISTER_REDIRECT_TYPES, static function (RegisterRedirectTypesEvent $e): void {
            $e->types[] = new class implements CustomRedirectMatcherInterface {
                public function handle(): string
                {
                    return 'legacy-id';
                }
                public function label(): string
                {
                    return 'Legacy ID';
                }
                public function match(string $pattern, string $uri): ?array
                {
                    return $pattern === 'article' && $uri === '/article/42'
                        ? ['captures' => ['$1' => '/news/2024/post'], 'query' => '']
                        : null;
                }
            };
        });

        $result = $matcher->matchRule('legacy-id', 'article', '/article/42');
        $this->assertNotNull($result);
        $this->assertSame(['$1' => '/news/2024/post'], $result['captures']);

        // The event fires only once (memoized).
        $this->assertSame(['legacy-id' => 'Legacy ID'], $matcher->customTypeLabels());
    }

    public function testMatchRuleReturnsNullForUnknownHandle(): void
    {
        $this->assertNull($this->matcher->matchRule('totally-made-up', '/x', '/x'));
    }
}
