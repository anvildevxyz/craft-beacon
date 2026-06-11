<?php

namespace anvildev\beacon\tests\unit\services\scoring;

use anvildev\beacon\services\scoring\ContentNode;
use anvildev\beacon\services\scoring\ContentWalker;
use PHPUnit\Framework\TestCase;

class ContentWalkerTest extends TestCase
{
    public function testEmitsHeadingsWithLevelTextAndWordCount(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<h2>Composer plugins must run first</h2>');

        $this->assertCount(1, $ast);
        $this->assertSame(ContentNode::TYPE_HEADING, $ast[0]->type);
        $this->assertSame(2, $ast[0]->level);
        $this->assertSame('Composer plugins must run first', $ast[0]->text);
        $this->assertSame(5, $ast[0]->wordCount);
    }

    public function testEmitsParagraphsWithWordCount(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p>One two three four five.</p>');

        $this->assertCount(1, $ast);
        $this->assertSame(ContentNode::TYPE_PARAGRAPH, $ast[0]->type);
        $this->assertSame('One two three four five.', $ast[0]->text);
        $this->assertSame(5, $ast[0]->wordCount);
    }

    public function testSkipsEmptyParagraphs(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p></p><p>   </p><p>real</p>');

        // Empty/whitespace-only paragraphs add noise to the AST without
        // contributing structure, so they must not appear in the list.
        $this->assertCount(1, $ast);
        $this->assertSame('real', $ast[0]->text);
    }

    public function testWalksHeadingsAndParagraphsInDocumentOrder(): void
    {
        $walker = new ContentWalker();
        $html = '<h2>First</h2><p>P1.</p><h2>Second</h2><p>P2.</p>';
        $ast = $walker->parseHtml($html);

        $this->assertCount(4, $ast);
        $this->assertSame('First', $ast[0]->text);
        $this->assertSame('P1.', $ast[1]->text);
        $this->assertSame('Second', $ast[2]->text);
        $this->assertSame('P2.', $ast[3]->text);
    }

    public function testRecursesIntoStructuralWrappers(): void
    {
        $walker = new ContentWalker();
        $html = '<article><section><div><h2>Buried heading</h2><p>Buried para.</p></div></section></article>';
        $ast = $walker->parseHtml($html);

        // Themes wrap content in arbitrary <article>/<section>/<div> chains;
        // the walker has to descend through them.
        $headings = array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_HEADING);
        $paragraphs = array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_PARAGRAPH);
        $this->assertCount(1, $headings);
        $this->assertCount(1, $paragraphs);
    }

    public function testEmitsListItemsAsListNode(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<ul><li>Apple</li><li>Pear</li><li>Plum</li></ul>');

        $this->assertCount(1, $ast);
        $this->assertSame(ContentNode::TYPE_LIST, $ast[0]->type);
        $this->assertSame(['Apple', 'Pear', 'Plum'], $ast[0]->items);
        $this->assertSame(3, $ast[0]->wordCount);
    }

    public function testExtractsLinksWithInternalFlag(): void
    {
        $walker = new ContentWalker();
        $html = '<p>See <a href="https://example.com/refs">refs</a> and <a href="https://other.org/x">other</a>.</p>';
        $ast = $walker->parseHtml($html, 'example.com');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(2, $links);
        $this->assertSame('https://example.com/refs', $links[0]->href);
        $this->assertTrue($links[0]->isInternal);
        $this->assertSame('https://other.org/x', $links[1]->href);
        $this->assertFalse($links[1]->isInternal);
    }

    public function testRelativeHrefsAreInternal(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p><a href="/about">about</a></p>', 'example.com');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(1, $links);
        $this->assertTrue($links[0]->isInternal);
    }

    public function testFragmentLinksAreIgnored(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p><a href="#section">jump</a></p>');

        // Fragment-only anchors aren't outbound citations or internal links
        // in any useful sense for the score; drop them at parse time so
        // pillars don't have to filter.
        $links = array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK);
        $this->assertCount(0, $links);
    }

    public function testWordCountAccountsForPunctuationAndUnicode(): void
    {
        // Tokens that are only punctuation must not count, but Unicode
        // letters (German Umlaute here) must.
        $this->assertSame(3, ContentNode::countWords('über das Wörterbuch'));
        $this->assertSame(0, ContentNode::countWords('— ; ,'));
        $this->assertSame(4, ContentNode::countWords('one — two; three, four.'));
    }

    public function testCodeBlocksPreserveText(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<pre><code>echo "hi";</code></pre>');

        $code = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_CODE));
        $this->assertCount(1, $code);
        $this->assertStringContainsString('echo', $code[0]->text);
    }

    public function testReturnsEmptyArrayOnEmptyHtml(): void
    {
        $walker = new ContentWalker();
        $this->assertSame([], $walker->parseHtml(''));
        $this->assertSame([], $walker->parseHtml('   '));
    }

    public function testEmitsTableAsTableNode(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<table><tr><td>Alpha beta gamma</td></tr></table>');

        $tables = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_TABLE));
        $this->assertCount(1, $tables);
        $this->assertSame('Alpha beta gamma', $tables[0]->text);
        $this->assertSame(3, $tables[0]->wordCount);
    }

    public function testEmitsOrderedListsLikeUnordered(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<ol><li>One</li><li>Two</li></ol>');

        $this->assertCount(1, $ast);
        $this->assertSame(ContentNode::TYPE_LIST, $ast[0]->type);
        $this->assertSame(['One', 'Two'], $ast[0]->items);
    }

    public function testSkipsEmptyListsAndTables(): void
    {
        $walker = new ContentWalker();
        // No item/cell text → no node (nothing structural to score).
        $this->assertSame([], $walker->parseHtml('<ul></ul><ol><li>  </li></ol><table>  </table>'));
    }

    public function testProtocolRelativeLinkIsExternal(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p><a href="//cdn.other.org/x">x</a></p>', 'example.com');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(1, $links);
        $this->assertFalse($links[0]->isInternal);
    }

    public function testMailtoAndTelLinksAreInternal(): void
    {
        $walker = new ContentWalker();
        $ast = $walker->parseHtml('<p><a href="mailto:a@b.com">mail</a><a href="tel:+15551234">call</a></p>', 'example.com');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(2, $links);
        $this->assertTrue($links[0]->isInternal);
        $this->assertTrue($links[1]->isInternal);
    }

    public function testAbsoluteLinkIsExternalWhenSelfHostUnknown(): void
    {
        $walker = new ContentWalker();
        // No self-host given → any host-bearing link can't be proven internal.
        $ast = $walker->parseHtml('<p><a href="https://example.com/x">x</a></p>');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(1, $links);
        $this->assertFalse($links[0]->isInternal);
    }

    public function testExtractsLinksNestedInHeadingsAndLists(): void
    {
        $walker = new ContentWalker();
        $html = '<h2>See <a href="https://ref.org/a">ref</a></h2><ul><li><a href="https://ref.org/b">b</a></li></ul>';
        $ast = $walker->parseHtml($html, 'example.com');

        $links = array_values(array_filter($ast, fn(ContentNode $n) => $n->type === ContentNode::TYPE_LINK));
        $this->assertCount(2, $links);
        $this->assertSame('https://ref.org/a', $links[0]->href);
        $this->assertSame('https://ref.org/b', $links[1]->href);
    }
}
