<?php

namespace anvildev\beacon\services\ai;

/**
 * Raised when an AI provider request fails (transport error, non-2xx
 * response, or an unparseable body). Callers in the CP layer translate this
 * into a user-facing flash / JSON error — it never bubbles to a fatal.
 */
final class AiException extends \RuntimeException
{
}
