<?php

namespace anvildev\beacon\tests\unit\helpers;

use anvildev\beacon\helpers\SafeRegex;
use PHPUnit\Framework\TestCase;

class SafeRegexTest extends TestCase
{
    public function testValidateRejectsEmptyPattern(): void
    {
        $this->assertSame('validation.regex.pattern.cannot.empty', SafeRegex::validate(''));
    }

    public function testValidateRejectsOverlongPattern(): void
    {
        $long = str_repeat('a', SafeRegex::MAX_PATTERN_LENGTH + 1);
        $this->assertSame('validation.regex.pattern.exceeds.characters', SafeRegex::validate($long));
    }

    public function testValidateAcceptsSimplePattern(): void
    {
        $this->assertNull(SafeRegex::validate('^/blog/\d+$'));
    }

    public function testValidateAcceptsPatternAtMaxLength(): void
    {
        $this->assertNull(SafeRegex::validate(str_repeat('a', SafeRegex::MAX_PATTERN_LENGTH)));
    }

    public function testValidateRejectsUncompilablePattern(): void
    {
        // Unbalanced group — fails to compile.
        $this->assertSame('validation.regex.pattern.invalid.too.expensive.evaluate', SafeRegex::validate('('));
    }

    public function testValidateRejectsCatastrophicBacktracking(): void
    {
        // Classic ReDoS pattern: against the 64×'a' + '!' probe it blows the
        // lowered backtrack limit, so validate() must reject it before it ever
        // reaches the request hot path.
        $this->assertSame(
            'validation.regex.pattern.invalid.too.expensive.evaluate',
            SafeRegex::validate('^(a+)+$'),
        );
    }

    public function testValidateEscapesDelimiterInRawPattern(): void
    {
        // A literal delimiter char in the pattern must be escaped, not read as
        // the closing delimiter (which would make the pattern uncompilable).
        $this->assertNull(SafeRegex::validate('foo#bar'));
    }

    public function testMatchReturnsTrueOnMatch(): void
    {
        $this->assertTrue(SafeRegex::match('#^/blog/\d+$#', '/blog/42'));
    }

    public function testMatchReturnsFalseOnNoMatch(): void
    {
        $this->assertFalse(SafeRegex::match('#^/blog/\d+$#', '/news/42'));
    }

    public function testMatchPopulatesCaptureGroups(): void
    {
        $matches = [];
        $this->assertTrue(SafeRegex::match('#^/blog/(\d+)$#', '/blog/42', $matches));
        $this->assertSame('42', $matches[1]);
    }

    public function testMatchReturnsNullOnInvalidPattern(): void
    {
        // Missing delimiters → compile failure → null (distinct from "no match").
        $this->assertNull(SafeRegex::match('^(a+', 'aaaa'));
    }
}
