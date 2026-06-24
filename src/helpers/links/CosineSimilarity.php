<?php

namespace anvildev\beacon\helpers\links;

class CosineSimilarity
{
    /**
     * Cosine similarity between two sparse vectors (keyword => weight maps).
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public static function calculate(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $dotProduct = 0.0;
        foreach ($a as $key => $valueA) {
            if (isset($b[$key])) {
                $dotProduct += $valueA * $b[$key];
            }
        }

        if ($dotProduct === 0.0) {
            return 0.0;
        }

        $magnitudeA = self::magnitude($a);
        $magnitudeB = self::magnitude($b);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * Cosine similarity between two dense float arrays (embeddings).
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function calculateFromArrays(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * @param array<string, float> $vector
     */
    private static function magnitude(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }
}
