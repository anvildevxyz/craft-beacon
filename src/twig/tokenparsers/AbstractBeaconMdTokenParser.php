<?php

namespace anvildev\beacon\twig\tokenparsers;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Shared parse logic for `{% beaconmd %}` / `{% beaconmdignore %}` block tags.
 */
abstract class AbstractBeaconMdTokenParser extends AbstractTokenParser
{
    abstract protected function endTag(): string;

    /**
     * @param Node $body inner block compiled by the subparser
     */
    abstract protected function createNode(Node $body, int $line, string $tag): Node;

    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);

        return $this->createNode($body, $token->getLine(), $this->getTag());
    }

    public function decideEnd(Token $token): bool
    {
        return $token->test($this->endTag());
    }
}
