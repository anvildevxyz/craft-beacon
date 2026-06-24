<?php

namespace anvildev\beacon\services\ai;

/**
 * Pure parsing/normalisation of raw LLM output. No I/O — unit-testable in
 * isolation. Models are chatty and inconsistent (code fences, wrapping quotes,
 * surrounding prose), so callers run their output through here before use.
 */
final class AiResponseParser
{
    /**
     * Collapse whitespace to single spaces and strip wrapping quotes the model
     * sometimes adds around a single-line answer (titles, descriptions).
     */
    public static function oneLine(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($collapsed, "\"' \t\n\r");
    }

    /**
     * Parse FAQ JSON, tolerating ```json fences and surrounding prose. Returns
     * only well-formed {question, answer} pairs.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function faq(string $raw): array
    {
        $json = trim($raw);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json) ?? $json;

        // Narrow to the outermost JSON array if there's surrounding prose.
        $start = strpos($json, '[');
        $end = strrpos($json, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($json, $start, $end - $start + 1);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = isset($row['question']) && is_string($row['question']) ? trim($row['question']) : '';
            $answer = isset($row['answer']) && is_string($row['answer']) ? trim($row['answer']) : '';
            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => $question, 'answer' => $answer];
            }
        }
        return $out;
    }
}
