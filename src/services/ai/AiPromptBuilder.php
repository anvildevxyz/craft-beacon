<?php

namespace anvildev\beacon\services\ai;

/**
 * Assembles the (system, user) prompt pair for each AI generation task.
 *
 * Pure string assembly — no I/O, no Craft calls — so every prompt shape is
 * trivially unit-testable. The {@see \anvildev\beacon\services\AiContentService}
 * supplies already-cleaned entry content and context.
 *
 * @phpstan-type PromptContext array{section?: string, geoScore?: int|null, weakPillars?: list<string>}
 * @phpstan-type Prompt array{system: string, user: string}
 */
final class AiPromptBuilder
{
    /** Hard cap on entry content fed to the model, to bound token cost. */
    public const MAX_CONTENT_CHARS = 6000;

    /**
     * @param PromptContext $context
     * @return Prompt
     */
    public function metaTitle(string $entryTitle, string $content, array $context = []): array
    {
        return [
            'system' => 'You are an expert SEO copywriter. You write concise, specific, '
                . 'click-worthy meta titles. Reply with the title text only — no quotes, no labels, no markdown.',
            'user' => "Write a single SEO meta title (50–60 characters) for this page.\n\n"
                . $this->contextBlock($context)
                . "Page title: {$entryTitle}\n\nContent:\n" . $this->trimContent($content),
        ];
    }

    /**
     * @param PromptContext $context
     * @return Prompt
     */
    public function metaDescription(string $entryTitle, string $content, array $context = []): array
    {
        return [
            'system' => 'You are an expert SEO copywriter. You write accurate, compelling meta '
                . 'descriptions that summarise the page and invite the click. Reply with the description '
                . 'text only — no quotes, no labels, no markdown.',
            'user' => "Write a single SEO meta description (150–160 characters) for this page.\n\n"
                . $this->contextBlock($context)
                . "Page title: {$entryTitle}\n\nContent:\n" . $this->trimContent($content),
        ];
    }

    /**
     * @param PromptContext $context
     * @return Prompt
     */
    public function summary(string $entryTitle, string $content, array $context = []): array
    {
        return [
            'system' => 'You write neutral, factual TL;DR summaries optimised for both humans and '
                . 'AI answer engines. Reply with 1–2 plain sentences only — no preamble, no markdown.',
            'user' => "Write a 1–2 sentence TL;DR summary of this page.\n\n"
                . $this->contextBlock($context)
                . "Page title: {$entryTitle}\n\nContent:\n" . $this->trimContent($content),
        ];
    }

    /**
     * @param PromptContext $context
     * @return Prompt
     */
    public function faq(string $entryTitle, string $content, array $context = []): array
    {
        return [
            'system' => 'You generate factual FAQ entries grounded strictly in the supplied content. '
                . 'Never invent facts. Reply with a JSON array only, each item '
                . '{"question": "...", "answer": "..."} — no markdown fences, no surrounding prose.',
            'user' => "Generate 3 to 5 frequently-asked questions and answers for this page, "
                . "using only facts present in the content.\n\n"
                . $this->contextBlock($context)
                . "Page title: {$entryTitle}\n\nContent:\n" . $this->trimContent($content),
        ];
    }

    /**
     * @return Prompt
     */
    public function altText(string $filename, string $entryTitle = ''): array
    {
        $where = $entryTitle !== '' ? " The image appears on a page titled \"{$entryTitle}\"." : '';
        return [
            'system' => 'You write concise, descriptive image alt text for accessibility and search. '
                . 'Reply with the alt text only (under 125 characters) — no quotes, no labels.',
            'user' => "Write alt text for an image with file name \"{$filename}\".{$where}",
        ];
    }

    /**
     * Renders the optional context preamble (section, current GEO score, and
     * the weakest GEO pillars) so the model can target known weaknesses.
     *
     * @param PromptContext $context
     */
    private function contextBlock(array $context): string
    {
        $lines = [];
        if (($context['section'] ?? '') !== '') {
            $lines[] = 'Section: ' . $context['section'];
        }
        if (($context['geoScore'] ?? null) !== null) {
            $lines[] = 'Current GEO readiness score: ' . $context['geoScore'] . '/100';
        }
        if (($context['weakPillars'] ?? []) !== []) {
            $lines[] = 'Weakest GEO pillars to improve: ' . implode(', ', $context['weakPillars']);
        }
        return $lines === [] ? '' : implode("\n", $lines) . "\n\n";
    }

    private function trimContent(string $content): string
    {
        $content = trim($content);
        if (mb_strlen($content) <= self::MAX_CONTENT_CHARS) {
            return $content;
        }
        return mb_substr($content, 0, self::MAX_CONTENT_CHARS) . "\n…[truncated]";
    }
}
