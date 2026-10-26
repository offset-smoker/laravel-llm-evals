<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Support;

final class Text
{
    /**
     * Shortens a string for a one-line report and strips control characters.
     */
    public static function excerpt(string $text, int $length = 60): string
    {
        $clean = self::terminalSafe(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($clean) > $length ? mb_substr($clean, 0, $length - 1) . '…' : $clean;
    }

    /**
     * Removes control characters (ANSI escapes included) except newlines and
     * tabs, so model output printed to a terminal or CI log cannot rewrite it.
     */
    public static function terminalSafe(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    }

    /**
     * preg_match without warnings: true or false for a result, null when the
     * pattern is invalid or the engine gave up (for example on its
     * backtracking limit).
     */
    public static function safePregMatch(string $pattern, string $subject): ?bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === false ? null : $result === 1;
    }
}
