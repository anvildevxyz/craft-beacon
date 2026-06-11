<?php

namespace anvildev\beacon\twig\tokenparsers;

use anvildev\beacon\twig\nodes\BeaconMdNode;
use Twig\Node\Node;

/**
 * Parses `{% beaconmd %} ... {% endbeaconmd %}`.
 *
 * Renders the inner block normally. The Markdown export pipeline only emits
 * content from inside these blocks when ANY block is present in the rendered
 * page — otherwise the whole page is exported.
 */
final class BeaconMdTokenParser extends AbstractBeaconMdTokenParser
{
    protected function endTag(): string
    {
        return 'endbeaconmd';
    }

    protected function createNode(Node $body, int $line, string $tag): Node
    {
        return new BeaconMdNode($body, $line, $tag);
    }

    public function getTag(): string
    {
        return 'beaconmd';
    }
}
