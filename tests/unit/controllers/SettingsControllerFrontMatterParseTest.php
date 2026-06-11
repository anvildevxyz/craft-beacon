<?php

namespace anvildev\beacon\tests\unit\controllers;

use anvildev\beacon\helpers\Strings;
use PHPUnit\Framework\TestCase;

/**
 * `Strings::parseKeyValueLines` is the user-facing serialization shared by the
 * Settings → GEO Markdown front-matter defaults, the sitemap per-section
 * overrides, and `BeaconSeoField::readAiMarkdownFor()`. All three call sites
 * must match: tests lock in the parsing rules.
 */
class SettingsControllerFrontMatterParseTest extends TestCase
{
    public function testEmptyInputProducesEmptyArray(): void
    {
        $this->assertSame([], $this->parse(''));
        $this->assertSame([], $this->parse("\n\n\n"));
    }

    public function testKeyValueLinesAreParsed(): void
    {
        $this->assertSame(
            ['audience' => 'developers', 'license' => 'MIT'],
            $this->parse("audience: developers\nlicense: MIT"),
        );
    }

    public function testWhitespaceAroundKeyAndValueIsTrimmed(): void
    {
        $this->assertSame(
            ['key' => 'value'],
            $this->parse("  key  :  value  "),
        );
    }

    public function testBlankLinesAreSkipped(): void
    {
        $this->assertSame(
            ['a' => '1', 'b' => '2'],
            $this->parse("\n\na: 1\n\n\nb: 2\n"),
        );
    }

    public function testLinesWithoutColonAreSkipped(): void
    {
        $this->assertSame(
            ['valid' => 'ok'],
            $this->parse("garbage line\nvalid: ok\nmore garbage"),
        );
    }

    public function testEmptyKeyIsSkipped(): void
    {
        $this->assertSame(
            ['x' => 'y'],
            $this->parse(": value\n  : another\nx: y"),
        );
    }

    public function testDoubleQuotedValuesAreUnwrapped(): void
    {
        $this->assertSame(['title' => 'Hello'], $this->parse('title: "Hello"'));
    }

    public function testSingleQuotedValuesAreUnwrapped(): void
    {
        $this->assertSame(['title' => 'Hello'], $this->parse("title: 'Hello'"));
    }

    public function testMismatchedQuotesAreLeftIntact(): void
    {
        $this->assertSame(['t' => '"Hello'], $this->parse('t: "Hello'));
        $this->assertSame(['t' => "'Hello\""], $this->parse("t: 'Hello\""));
    }

    public function testColonInValueIsPreservedAfterTheFirstSplit(): void
    {
        $this->assertSame(
            ['url' => 'https://example.com:8080/path'],
            $this->parse('url: https://example.com:8080/path'),
        );
    }

    public function testDuplicateKeysLastOneWins(): void
    {
        $this->assertSame(
            ['key' => 'second'],
            $this->parse("key: first\nkey: second"),
        );
    }

    public function testSingleCharacterValueIsNotMistakenForUnbalancedQuote(): void
    {
        $this->assertSame(['t' => '"'], $this->parse('t: "'));
    }

    /**
     * @return array<string,string>
     */
    private function parse(string $input): array
    {
        return Strings::parseKeyValueLines($input);
    }
}
