<?php

namespace anvildev\beacon\twig\nodes;

use anvildev\beacon\services\markdown\HtmlChromeStripper;

/**
 * Compiled output for `{% beaconmd %}…{% endbeaconmd %}`.
 *
 * Wraps the captured body in the keep markers consumed by
 * {@see HtmlChromeStripper::extractMarkedContent()}.
 */
final class BeaconMdNode extends AbstractBeaconMdMarkerNode
{
    protected function startMarker(): string
    {
        return HtmlChromeStripper::MARKER_KEEP_START;
    }

    protected function endMarker(): string
    {
        return HtmlChromeStripper::MARKER_KEEP_END;
    }
}
