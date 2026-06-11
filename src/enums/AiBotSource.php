<?php

namespace anvildev\beacon\enums;

/**
 * Provenance of an AI-bot row, persisted to `{{%beacon_ai_bots}}.source`:
 * `default` for the bundled seed list, `custom` for operator-added bots.
 */
enum AiBotSource: string
{
    case Custom = 'custom';
    case Default = 'default';
}
