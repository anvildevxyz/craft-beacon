<?php

namespace anvildev\beacon\enums;

use Craft;

/**
 * Why an AI bot visits, persisted to `{{%beacon_ai_bots}}.purpose`. Lets an
 * editor apply an allow/disallow crawler policy by purpose (see
 * `docs/GEO_OPERATIONAL_PLAYBOOK.md`) instead of having to recognize each
 * bot name individually. A bot with no purpose set (null on the row) is
 * left out of every purpose-scoped filter and bulk action.
 */
enum BotPurpose: string
{
    case Training = 'training';
    case SearchIndex = 'searchIndex';
    case UserAgent = 'userAgent';

    public function label(): string
    {
        return match ($this) {
            self::Training => Craft::t('beacon', 'aiCrawlers.purpose.training.text'),
            self::SearchIndex => Craft::t('beacon', 'aiCrawlers.purpose.searchIndex.text'),
            self::UserAgent => Craft::t('beacon', 'aiCrawlers.purpose.userAgent.text'),
        };
    }
}
