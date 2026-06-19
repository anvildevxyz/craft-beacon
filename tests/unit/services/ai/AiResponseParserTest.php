<?php

namespace anvildev\beacon\tests\unit\services\ai;

use anvildev\beacon\services\ai\AiResponseParser;
use PHPUnit\Framework\TestCase;

class AiResponseParserTest extends TestCase
{
    public function testOneLineCollapsesWhitespaceAndStripsQuotes(): void
    {
        $this->assertSame('Hello world', AiResponseParser::oneLine("  \"Hello\n   world\"  "));
    }

    public function testFaqParsesPlainJsonArray(): void
    {
        $raw = '[{"question":"What is it?","answer":"A plugin."},{"question":"Why?","answer":"GEO."}]';
        $faq = AiResponseParser::faq($raw);
        $this->assertCount(2, $faq);
        $this->assertSame('What is it?', $faq[0]['question']);
        $this->assertSame('A plugin.', $faq[0]['answer']);
    }

    public function testFaqStripsCodeFencesAndProse(): void
    {
        $raw = "Here you go:\n```json\n[{\"question\":\"Q\",\"answer\":\"A\"}]\n```\nHope that helps!";
        $faq = AiResponseParser::faq($raw);
        $this->assertCount(1, $faq);
        $this->assertSame('Q', $faq[0]['question']);
    }

    public function testFaqDropsMalformedRows(): void
    {
        $raw = '[{"question":"Q","answer":"A"},{"question":"","answer":"x"},{"foo":"bar"},"nope"]';
        $faq = AiResponseParser::faq($raw);
        $this->assertCount(1, $faq);
    }

    public function testFaqReturnsEmptyOnGarbage(): void
    {
        $this->assertSame([], AiResponseParser::faq('not json at all'));
        $this->assertSame([], AiResponseParser::faq(''));
    }
}
