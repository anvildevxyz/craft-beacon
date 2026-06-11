<?php

namespace anvildev\beacon\twig\tokenparsers;

use anvildev\beacon\twig\nodes\BeaconMdIgnoreNode;
use Twig\Node\Node;

/**
 * Parses `{% beaconmdignore %} ... {% endbeaconmdignore %}`.
 *
 * Wraps the inner block in markers that the Markdown export pipeline strips
 * before HTML→MD conversion.
 */
final class BeaconMdIgnoreTokenParser extends AbstractBeaconMdTokenParser
{
    protected function endTag(): string
    {
        return 'endbeaconmdignore';
    }

    protected function createNode(Node $body, int $line, string $tag): Node
    {
        return new BeaconMdIgnoreNode($body, $line, $tag);
    }

    public function getTag(): string
    {
        return 'beaconmdignore';
    }
}
