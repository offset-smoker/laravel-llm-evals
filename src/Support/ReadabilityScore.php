<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Support;

/**
 * Flesch-Kincaid grade level for English text:
 * 0.39 × (words / sentences) + 11.8 × (syllables / words) − 15.59.
 */
final class ReadabilityScore
{
    public static function fleschKincaidGrade(string $text): ?float
    {
        $words = self::words($text);

        if ($words === []) {
            return null;
        }

        $sentences = max(1, preg_match_all('/[.!?]+(?=\s|$)/u', $text));
        $syllables = array_sum(array_map(self::syllables(...), $words));
        $wordCount = count($words);

        return 0.39 * ($wordCount / $sentences) + 11.8 * ($syllables / $wordCount) - 15.59;
    }

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all("/[\\p{L}\\p{N}]+(?:['’][\\p{L}]+)*/u", $text, $matches);

        return $matches[0];
    }

    public static function syllables(string $word): int
    {
        $word = mb_strtolower($word);

        if (preg_match('/^\p{N}+$/u', $word) === 1) {
            return 1;
        }

        $word = preg_replace("/['’].*$/u", '', $word) ?? $word;

        if (mb_strlen($word) <= 3) {
            return 1;
        }

        // A silent final "e" ("make"), and "-es"/"-ed" endings that add no syllable ("makes", "saved").
        $word = preg_replace('/(?:[^laeiouy]es|[^laeiouy]ed|[^laeiouy]e)$/u', 'x', $word) ?? $word;
        $word = preg_replace('/^y/u', '', $word) ?? $word;

        return max(1, preg_match_all('/[aeiouy]+/u', $word));
    }
}
