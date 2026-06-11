<?php

declare(strict_types=1);

/**
 * Curated fact-density paragraphs for the regression test in
 * {@see \anvildev\beacon\tests\unit\services\scoring\heuristics\FactDetectorsTest::testFalsePositiveCeilingOnHandCuratedDeck}.
 *
 * Each row carries text + the detector's expected fact count
 * (numeric + date + named-entity; citation links are scored separately).
 * Counts were calibrated against the current detector implementation —
 * the deck is a *regression contract*, not a human ground truth. If a
 * detector tweak changes a count, update the expected value here as
 * part of the same PR so the change is auditable.
 *
 * The ±20% tolerance still applies on top, so trivial drift (one detector
 * tightening by a single token) doesn't force a fixture edit.
 */

return [
    [
        'label' => 'high-density-tech-news',
        'text' => 'Anthropic released Claude 4 on 2026-05-22 with 200K context windows and 3.2x faster inference. The launch followed $750M in Series E funding raised in March 2026.',
        // $750M, 3.2x, 2026-05-22, March 2026, "Anthropic released", "Series E funding raised"
        'expected' => 6,
    ],
    [
        'label' => 'medium-density-product',
        // Currently misses "5 widgets" (single digit, by design) and
        // "Beta users report" (entity detector skips when no clear
        // sentence boundary after Beta). Treated as the detector floor.
        'text' => 'The new dashboard ships with 5 widgets and reduces load time by 40%. Beta users report 12 GB of memory savings.',
        'expected' => 2,
    ],
    [
        'label' => 'narrative-no-facts',
        'text' => 'The team worked through the night to finish the release. Everyone agreed the result was worth the effort, and the morale lift carried into the next sprint.',
        'expected' => 0,
    ],
    [
        'label' => 'mixed-prose-and-stats',
        'text' => 'Conversion rose 23% after we shipped the new flow in April 2026. Our analysis covered 1,500 sessions across 8 countries.',
        // 23%, 1,500, April 2026 — "8 countries" rejected as single digit;
        // "we shipped" and "Our analysis covered" rejected (pronoun, "covered" not in verb list).
        'expected' => 3,
    ],
    [
        'label' => 'date-heavy-roadmap',
        'text' => 'Version 1.0 ships on 2026-06-01 with the milestone branch frozen since 2026-05-15. The Q3 2026 release will close the gap to ChatGPT parity.',
        // 1.0, 2026-06-01, 2026-05-15, "since 2026-05-15", "Q3 2026"
        'expected' => 5,
    ],
    [
        'label' => 'unit-heavy-spec',
        'text' => 'The cluster runs on 64 GB RAM nodes, 100 Gb/s NICs, and 4 TB NVMe drives. Latency p99 stays under 50 ms.',
        // 64 GB, 4 TB, 50 ms, plus "100" as bare integer (Gb/s not in units list).
        'expected' => 4,
    ],
    [
        'label' => 'currency-only-funding',
        'text' => 'Seed round closed at $4.5M. Series A added $22M in February 2025, valuing the company near $200M.',
        'expected' => 4,
    ],
    [
        'label' => 'pronoun-heavy-essay',
        'text' => 'They said it was the best release yet. We tested it for weeks and everyone agreed. It launched without issues, and they were thrilled.',
        'expected' => 0,
    ],
    [
        'label' => 'german-news',
        'text' => 'Beacon hat im April 2026 die GEO-Scoring-Funktion eingeführt. Die Latenz sank um 40%. Unsere Tests umfassten 1.000 Einträge.',
        // 40%, 1.000, April 2026. ("Beacon hat" not matched — "hat" not in
        // English reporting-verb list; v3.2 LLM mode would catch it.)
        'expected' => 3,
    ],
    [
        'label' => 'historical-prose',
        'text' => 'The internet emerged in the 1990s as a research network. By 2010, broadband had become commonplace across most of Europe.',
        // "1990s" (numeric), "in the 1990s" (date), "By 2010" (date) — detector
        // counts both the decade-bare token and the "in the 1990s" phrase.
        'expected' => 3,
    ],
    [
        'label' => 'numeric-trap-page-refs',
        'text' => 'See page 1, page 2, and page 3 for the full breakdown of the methodology used throughout the report.',
        'expected' => 0,
    ],
    [
        'label' => 'mixed-citations-no-numerics',
        'text' => 'Google has acquired several AI startups in recent years. Microsoft announced a new partnership with OpenAI. Meta released LLaMA 3 to the open-source community.',
        'expected' => 3,
    ],
];
