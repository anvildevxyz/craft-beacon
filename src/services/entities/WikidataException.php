<?php

namespace anvildev\beacon\services\entities;

/**
 * Raised by {@see WikidataClientInterface} implementations on transport
 * failure. {@see \anvildev\beacon\services\WikidataService} catches it and
 * degrades to an empty result so the picker never breaks the entry editor.
 */
final class WikidataException extends \RuntimeException
{
}
