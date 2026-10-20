<?php

namespace anvildev\beacon\migrations;

use anvildev\beacon\records\AiBotRecord;
use craft\db\Migration;

/**
 * Adds the nullable `purpose` column to `{{%beacon_ai_bots}}` and backfills
 * it for every bot in the curated default list as it stood before this
 * migration (see `AiBotsService::DEFAULT_BOTS` for the current list —
 * intentionally not referenced here, so this migration keeps replaying the
 * same backfill regardless of later changes to that constant).
 *
 * Bots renamed or added by this same release (`Claude-Web` → `Claude-User` /
 * `Claude-SearchBot`, `Perplexity-User`, `Meta-ExternalAgent`,
 * `Meta-ExternalFetcher`) aren't in this list: they either didn't exist in
 * any pre-upgrade row (so there's nothing here to backfill) or, for
 * `Claude-Web`, are being retired and are left with no purpose rather than
 * guessed at. A custom bot an operator added keeps whatever purpose (or
 * lack of one) it already had.
 */
class m261020_000001_ai_bot_purpose extends Migration
{
    /**
     * Diffbot isn't named in the GEO research that drove this change, but
     * crawler-purpose directories (e.g. Cloudflare's, darkvisitors.com)
     * consistently classify it as an AI/ML data-scraping crawler, so it's
     * folded into `training` here rather than left unset.
     *
     * @var array<string, list<string>>
     */
    private const PURPOSE_BY_NAME = [
        'training' => ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Bytespider', 'Applebot-Extended', 'cohere-ai', 'Diffbot'],
        'searchIndex' => ['OAI-SearchBot', 'PerplexityBot', 'Amazonbot'],
        'userAgent' => ['ChatGPT-User'],
    ];

    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%beacon_ai_bots}}', 'purpose')) {
            $this->addColumn(
                '{{%beacon_ai_bots}}',
                'purpose',
                $this->string(16)->null()->after('source'),
            );
        }

        foreach (self::PURPOSE_BY_NAME as $purpose => $names) {
            $this->update('{{%beacon_ai_bots}}', ['purpose' => $purpose], ['name' => $names]);
        }

        // A raw Command update (above) bypasses the ActiveRecord lifecycle, so
        // it doesn't trip AiBotRecord's own cache-tag invalidation.
        AiBotRecord::invalidateCacheTag();

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%beacon_ai_bots}}', 'purpose')) {
            $this->dropColumn('{{%beacon_ai_bots}}', 'purpose');
        }

        return true;
    }
}
