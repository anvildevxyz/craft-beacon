<?php

namespace anvildev\beacon\tests\unit\helpers\links;

use anvildev\beacon\helpers\links\SentenceSplitter;
use PHPUnit\Framework\TestCase;

class SentenceSplitterTest extends TestCase
{
    public function testSplitsSimplePair(): void
    {
        $this->assertSame(['Hello world.', 'Goodbye world.'], SentenceSplitter::split('Hello world. Goodbye world.'));
    }

    public function testSplitsOnQuestionAndExclamation(): void
    {
        $this->assertSame(
            ['Hello!', 'World?', 'End.'],
            SentenceSplitter::split('Hello! World? End.')
        );
    }

    public function testReturnsEmptyArrayForEmptyString(): void
    {
        $this->assertSame([], SentenceSplitter::split(''));
    }

    public function testReturnsSingleItemForNoSplit(): void
    {
        $this->assertSame(['Hello world'], SentenceSplitter::split('Hello world'));
    }

    public function testDoesNotSplitOnHonorificAbbreviations(): void
    {
        $this->assertSame(
            ['Dr. Smith is here.', 'He works nearby.'],
            SentenceSplitter::split('Dr. Smith is here. He works nearby.')
        );
    }

    public function testDoesNotSplitOnMultipleHonorifics(): void
    {
        $this->assertSame(
            ['Mr. Jones met Mrs. Jones at St. Mary.'],
            SentenceSplitter::split('Mr. Jones met Mrs. Jones at St. Mary.')
        );
    }

    public function testDoesNotSplitOnSingleLetterInitials(): void
    {
        $this->assertSame(
            ['The U.S. economy grew.', 'Stocks rose.'],
            SentenceSplitter::split('The U.S. economy grew. Stocks rose.')
        );
    }

    public function testDoesNotSplitOnMultipleInitials(): void
    {
        $this->assertSame(
            ['J.R.R. Tolkien wrote books.'],
            SentenceSplitter::split('J.R.R. Tolkien wrote books.')
        );
    }

    public function testDoesNotSplitOnCommonLatinAbbreviations(): void
    {
        $this->assertSame(
            ['Visit e.g. this page.', 'Now go.'],
            SentenceSplitter::split('Visit e.g. this page. Now go.')
        );
    }

    public function testDoesNotSplitOnCompanyAbbreviations(): void
    {
        $this->assertSame(
            ['Acme Inc. bought Globex Ltd. yesterday.'],
            SentenceSplitter::split('Acme Inc. bought Globex Ltd. yesterday.')
        );
    }

    public function testHandlesMultibyteContent(): void
    {
        $this->assertSame(
            ['Über uns.', 'Willkommen.'],
            SentenceSplitter::split('Über uns. Willkommen.')
        );
    }

    public function testCollapsesMultipleWhitespace(): void
    {
        $this->assertSame(
            ['One.', 'Two.'],
            SentenceSplitter::split("One.\n\n  Two.")
        );
    }

    public function testTrimsOuterWhitespace(): void
    {
        $this->assertSame(['Hello.'], SentenceSplitter::split('  Hello.  '));
    }
}
