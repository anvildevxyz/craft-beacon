<?php

namespace anvildev\beacon\models;

use anvildev\beacon\helpers\SafeRegex;

class BotDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $userAgentPattern,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Bot name cannot be empty.');
        }
    }

    public function matches(string $userAgent): bool
    {
        $delimited = '#' . str_replace('#', '\\#', $this->userAgentPattern) . '#i';
        return SafeRegex::match($delimited, $userAgent) === true;
    }
}
