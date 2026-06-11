<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\Strings;
use PHPUnit\Framework\TestCase;

class StringsTest extends TestCase
{
    public function testSplitLinesSplitsOnAllLineEndings(): void
    {
        $this->assertSame(['a', 'b', 'c'], Strings::splitLines("a\r\nb\rc"));
    }

    public function testSplitLinesTrimsAndDropsEmptiesByDefault(): void
    {
        $this->assertSame(['a', 'b'], Strings::splitLines("  a  \n\n  \nb"));
    }

    public function testSplitLinesReindexesAsList(): void
    {
        $this->assertSame([0, 1], array_keys(Strings::splitLines("\n\nx\ny")));
    }

    public function testSplitLinesKeepsRawWhenTrimDisabled(): void
    {
        $this->assertSame(['  a  ', 'b'], Strings::splitLines("  a  \n\nb", false));
    }

    public function testTrimToNullReturnsTrimmedString(): void
    {
        $this->assertSame('x', Strings::trimToNull('  x  '));
    }

    public function testTrimToNullForBlankAndNonStrings(): void
    {
        $this->assertNull(Strings::trimToNull('   '));
        $this->assertNull(Strings::trimToNull(''));
        $this->assertNull(Strings::trimToNull(null));
        $this->assertNull(Strings::trimToNull(42));
        $this->assertNull(Strings::trimToNull(['x']));
    }

    public function testParseKeyValueLinesBuildsMap(): void
    {
        $this->assertSame(['a' => '1', 'b' => '2'], Strings::parseKeyValueLines("a: 1\nb: 2"));
    }

    public function testParseKeyValueLinesDropsEmptyAndColonlessLines(): void
    {
        $this->assertSame(['a' => '1'], Strings::parseKeyValueLines("a: 1\nnocolon\n\n"));
    }

    public function testParseKeyValueLinesDropsEmptyKey(): void
    {
        $this->assertSame([], Strings::parseKeyValueLines(': value'));
    }

    public function testParseKeyValueLinesStripsBalancedQuotes(): void
    {
        $this->assertSame(['a' => 'x', 'b' => 'y'], Strings::parseKeyValueLines("a: \"x\"\nb: 'y'"));
    }

    public function testParseKeyValueLinesKeepsLoneQuote(): void
    {
        // The strlen >= 2 guard: a single quote char must not unwrap to ''.
        $this->assertSame(['a' => '"'], Strings::parseKeyValueLines('a: "'));
    }

    public function testParseKeyValueLinesKeepsUnbalancedQuotes(): void
    {
        $this->assertSame(['a' => '"x\''], Strings::parseKeyValueLines('a: "x\''));
    }

    public function testParseKeyValueLinesValueMayContainColon(): void
    {
        $this->assertSame(['url' => 'https://x.com'], Strings::parseKeyValueLines('url: https://x.com'));
    }

    public function testParseKeyValueLinesLastKeyWins(): void
    {
        $this->assertSame(['a' => '2'], Strings::parseKeyValueLines("a: 1\na: 2"));
    }
}
