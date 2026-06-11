<?php

namespace anvildev\beacon\twig;

use anvildev\beacon\twig\tokenparsers\BeaconMdIgnoreTokenParser;
use anvildev\beacon\twig\tokenparsers\BeaconMdTokenParser;
use Twig\Extension\AbstractExtension;

/**
 * Registers the `{% beaconmd %}` and `{% beaconmdignore %}` Twig tags.
 *
 * The tags are no-ops at HTML render time — they emit invisible HTML comment
 * markers (`<!--beacon:md-keep-start-->`, `<!--beacon:md-drop-start-->`, …)
 * that the GEO Markdown export pipeline interprets when it scrapes the
 * rendered template. Public site visitors see the markers as plain HTML
 * comments (browser strips them on render).
 */
final class GeoMarkdownExtension extends AbstractExtension
{
    /**
     * @return list<\Twig\TokenParser\TokenParserInterface>
     */
    public function getTokenParsers(): array
    {
        return [new BeaconMdTokenParser(), new BeaconMdIgnoreTokenParser()];
    }
}
