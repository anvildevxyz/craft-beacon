<?php

namespace anvildev\beacon\tests\unit\Tracking;

use anvildev\beacon\services\SiteOverrideResolver;
use PHPUnit\Framework\TestCase;

final class SiteOverrideResolverTest extends TestCase
{
    public function testNoOverrideReturnsGlobal(): void
    {
        $resolver = new SiteOverrideResolver();
        $resolved = $resolver->resolve(['measurementId' => 'G-A'], null, 'site-uid-1');
        $this->assertSame(['measurementId' => 'G-A'], $resolved);
    }

    public function testDifferentSiteReturnsGlobal(): void
    {
        $resolver = new SiteOverrideResolver();
        $resolved = $resolver->resolve(
            ['measurementId' => 'G-A'],
            ['site-uid-2' => ['config' => ['measurementId' => 'G-B']]],
            'site-uid-1',
        );
        $this->assertSame(['measurementId' => 'G-A'], $resolved);
    }

    public function testMatchingSiteMergesConfigOverGlobal(): void
    {
        $resolver = new SiteOverrideResolver();
        $resolved = $resolver->resolve(
            ['measurementId' => 'G-A', 'foo' => 'global'],
            ['site-uid-1' => ['config' => ['measurementId' => 'G-B']]],
            'site-uid-1',
        );
        $this->assertSame(['measurementId' => 'G-B', 'foo' => 'global'], $resolved);
    }

    public function testIsDisabledForSite(): void
    {
        $resolver = new SiteOverrideResolver();
        $this->assertTrue($resolver->isDisabledForSite(['site-uid-1' => ['enabled' => false]], 'site-uid-1'));
        $this->assertFalse($resolver->isDisabledForSite(['site-uid-1' => ['enabled' => true]], 'site-uid-1'));
        $this->assertFalse($resolver->isDisabledForSite(null, 'site-uid-1'));
    }
}
