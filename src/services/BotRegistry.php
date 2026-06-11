<?php

namespace anvildev\beacon\services;

use anvildev\beacon\models\AiBot;
use anvildev\beacon\models\BotDefinition;
use anvildev\beacon\Plugin;
use yii\base\Component;

class BotRegistry extends Component
{
    /** @var list<BotDefinition>|null Per-request memo; null = not yet loaded. */
    private ?array $memo = null;

    /**
     * @return list<BotDefinition>
     */
    public function getBots(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $aiBots = Plugin::$plugin?->get('aiBots', false);
        if (!$aiBots instanceof AiBotsService) {
            return $this->memo = [];
        }

        return $this->memo = array_map(
            fn(AiBot $b) => new BotDefinition($b->name, $b->userAgentPattern),
            $aiBots->getEnabledBots(),
        );
    }

    public function match(string $userAgent): ?BotDefinition
    {
        foreach ($this->getBots() as $bot) {
            if ($bot->matches($userAgent)) {
                return $bot;
            }
        }
        return null;
    }
}
