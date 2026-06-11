<?php

namespace anvildev\beacon\twig\nodes;

use anvildev\beacon\services\markdown\HtmlChromeStripper;

/**
 * Compiled output for `{% beaconmdignore %}…{% endbeaconmdignore %}`.
 *
 * Wraps the captured body in the drop markers consumed by
 * {@see HtmlChromeStripper::extractMarkedContent()}.
 */
final class BeaconMdIgnoreNode extends AbstractBeaconMdMarkerNode
{
    protected function startMarker(): string
    {
        return HtmlChromeStripper::MARKER_DROP_START;
    }

    protected function endMarker(): string
    {
        return HtmlChromeStripper::MARKER_DROP_END;
    }
}
