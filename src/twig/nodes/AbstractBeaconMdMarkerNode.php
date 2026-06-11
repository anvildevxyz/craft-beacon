<?php

namespace anvildev\beacon\twig\nodes;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Base for GEO Markdown marker nodes that wrap a Twig body in HTML comments
 * consumed by {@see \anvildev\beacon\services\markdown\HtmlChromeStripper}.
 */
abstract class AbstractBeaconMdMarkerNode extends Node
{
    public function __construct(Node $body, int $line, string $tag)
    {
        parent::__construct(['body' => $body], [], $line, $tag);
    }

    abstract protected function startMarker(): string;

    abstract protected function endMarker(): string;

    public function compile(Compiler $compiler): void
    {
        $start = var_export($this->startMarker(), true);
        $end = var_export($this->endMarker(), true);

        $compiler
            ->addDebugInfo($this)
            ->write("echo $start;\n")
            ->subcompile($this->getNode('body'))
            ->write("echo $end;\n");
    }
}
