<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\LinkParser;
use PHPUnit\Framework\TestCase;

class LinkParserTest extends TestCase
{
    public function testExtractsInternalUrls(): void
    {
        $html = '<p>Check out <a href="https://example.com/blog/my-post">this post</a> and '
            . '<a href="https://example.com/about">about us</a>.</p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/blog/my-post', $urls);
        $this->assertContains('https://example.com/about', $urls);
    }

    public function testIgnoresExternalUrls(): void
    {
        $html = '<p><a href="https://google.com">Google</a> <a href="https://example.com/page">Internal</a></p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/page', $urls);
    }

    public function testHandlesRelativeUrls(): void
    {
        $html = '<p><a href="/blog/post">Post</a> <a href="/about">About</a></p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/blog/post', $urls);
        $this->assertContains('https://example.com/about', $urls);
    }

    public function testIgnoresAnchorLinks(): void
    {
        $html = '<p><a href="#section">Jump</a> <a href="https://example.com/page#section">Page</a></p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/page', $urls);
    }

    public function testIgnoresMailtoAndTelLinks(): void
    {
        $html = '<p><a href="mailto:test@example.com">Email</a> <a href="tel:+1234567890">Call</a> <a href="https://example.com/contact">Contact</a></p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
    }

    public function testDeduplicatesUrls(): void
    {
        $html = '<p><a href="https://example.com/page">First</a> <a href="https://example.com/page">Second</a></p>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
    }

    public function testHandlesEmptyHtml(): void
    {
        $this->assertSame([], LinkParser::extractUrls('', 'https://example.com'));
    }

    public function testHandlesNoLinks(): void
    {
        $this->assertSame([], LinkParser::extractUrls('<p>No links.</p>', 'https://example.com'));
    }

    public function testStripsQueryStringAndFragment(): void
    {
        $html = '<a href="https://example.com/page?utm_source=foo#bar">Link</a>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/page', $urls);
    }

    public function testHandlesTrailingSlashNormalization(): void
    {
        $html = '<a href="https://example.com/page/">Link 1</a><a href="https://example.com/page">Link 2</a>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(1, $urls);
    }

    public function testExtractLinksReturnsAnchorText(): void
    {
        $html = '<p>Visit <a href="https://example.com/about">About Us</a> for more info.</p>';
        $links = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/about', $links[0]['url']);
        $this->assertSame('About Us', $links[0]['anchorText']);
        $this->assertFalse($links[0]['isExternal']);
    }

    public function testExtractLinksIdentifiesExternalUrls(): void
    {
        $html = '<p><a href="https://google.com/search">Search</a></p>';
        $links = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(1, $links);
        $this->assertSame('https://google.com/search', $links[0]['url']);
        $this->assertTrue($links[0]['isExternal']);
    }

    public function testExtractLinksStripsHtmlFromAnchorText(): void
    {
        $html = '<a href="https://example.com/about"><strong>About</strong> Us</a>';
        $links = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(1, $links);
        $this->assertSame('About Us', $links[0]['anchorText']);
    }

    public function testExtractLinksSkipsMailtoAndTel(): void
    {
        $html = '<a href="mailto:hello@example.com">Email</a> <a href="tel:+1234567890">Call</a>';
        $links = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(0, $links);
    }

    /** @dataProvider dangerousSchemeProvider */
    public function testExtractLinksFiltersNonHttpSchemes(string $dangerousHref): void
    {
        $html = '<a href="' . $dangerousHref . '">Click</a>';
        $links = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(0, $links, "Expected $dangerousHref to be filtered");
    }

    /** @dataProvider dangerousSchemeProvider */
    public function testExtractUrlsFiltersNonHttpSchemes(string $dangerousHref): void
    {
        $html = '<a href="' . $dangerousHref . '">Click</a>';
        $urls = LinkParser::extractUrls($html, 'https://example.com');
        $this->assertCount(0, $urls, "Expected $dangerousHref to be filtered");
    }

    /** @return array<string, array{string}> */
    public static function dangerousSchemeProvider(): array
    {
        return [
            'data URI' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript URI' => ['vbscript:MsgBox(1)'],
            'file URI' => ['file:///etc/passwd'],
            'blob URI' => ['blob:https://example.com/fake'],
            'javascript URI' => ['javascript:alert(1)'],
        ];
    }

    // --- Scheme allowlist tests for extractLinks ---

    public function testExtractLinksBlocksDataScheme(): void
    {
        $html = '<a href="data:text/html,<h1>xss</h1>">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertSame([], $result);
    }

    public function testExtractLinksBlocksVbscript(): void
    {
        $html = '<a href="vbscript:MsgBox">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertSame([], $result);
    }

    public function testExtractLinksBlocksBlob(): void
    {
        $html = '<a href="blob:https://evil.com/uuid">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertSame([], $result);
    }

    public function testExtractLinksBlocksJavascript(): void
    {
        $html = '<a href="javascript:alert(1)">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertSame([], $result);
    }

    public function testExtractLinksAllowsHttp(): void
    {
        $html = '<a href="http://external.com/page">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['isExternal']);
        $this->assertSame('http://external.com/page', $result[0]['url']);
    }

    public function testExtractLinksAllowsRelativePath(): void
    {
        $html = '<a href="/about">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['isExternal']);
    }

    public function testExtractLinksBlocksFtpScheme(): void
    {
        $html = '<a href="ftp://example.com/file">click</a>';
        $result = LinkParser::extractLinks($html, 'https://example.com');
        $this->assertSame([], $result);
    }
}
