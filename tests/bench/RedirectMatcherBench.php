<?php

namespace anvildev\beacon\tests\bench;

use anvildev\beacon\enums\RedirectType;
use anvildev\beacon\services\RedirectMatcher;

/**
 * Pure-PHP benchmark for RedirectMatcher (no Craft bootstrap required).
 *
 * @BeforeMethods({"setUp"})
 * @Iterations(100)
 * @Revs(50)
 */
class RedirectMatcherBench
{
    private RedirectMatcher $matcher;

    public function setUp(): void
    {
        $this->matcher = new RedirectMatcher();
    }

    public function benchExactMatch(): void
    {
        $this->matcher->matches('/old/path', RedirectType::Exact, '/old/path');
    }

    public function benchExactMiss(): void
    {
        $this->matcher->matches('/old/path', RedirectType::Exact, '/different/path');
    }

    public function benchGlobSingleSegment(): void
    {
        $this->matcher->matches('/blog/*', RedirectType::Glob, '/blog/post-12345');
    }

    public function benchGlobMultiSegment(): void
    {
        $this->matcher->matches('/old/**', RedirectType::Glob, '/old/foo/bar/baz/qux');
    }

    public function benchRegexMatch(): void
    {
        $this->matcher->matches('^/legacy/(\d+)$', RedirectType::Regex, '/legacy/123');
    }
}
