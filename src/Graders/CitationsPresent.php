<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use InvalidArgumentException;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Support\Text;

/**
 * Passes when the response cites at least `min` sources, every cited id is
 * one of the case's sources (no invented citations), and, with `all`, every
 * source is cited. Citations look like `[notice-101]` by default; pass a
 * pattern with a named `id` group to match another format.
 */
final readonly class CitationsPresent implements Grader
{
    public const string DEFAULT_PATTERN = '/\[(?<id>[A-Za-z0-9][A-Za-z0-9._:\-]{0,127})\]/';

    public function __construct(
        private int $min = 1,
        private bool $all = false,
        private string $pattern = self::DEFAULT_PATTERN,
    ) {
        if ($min < 1) {
            throw new InvalidArgumentException('min must be at least 1.');
        }

        if (Text::safePregMatch($pattern, '') === null || ! str_contains($pattern, '(?<id>')) {
            throw new InvalidArgumentException('pattern must be a valid PCRE pattern with a named group (?<id>...).');
        }
    }

    public function describe(): string
    {
        return $this->all ? 'cites every source' : "cites at least {$this->min} source" . ($this->min === 1 ? '' : 's');
    }

    public function grade(Response $response, EvalCase $case): Grade
    {
        set_error_handler(static fn(): bool => true);

        try {
            $count = preg_match_all($this->pattern, $response->text, $matches);
        } finally {
            restore_error_handler();
        }

        if ($count === false) {
            return Grade::fail('the citation pattern could not be evaluated (' . preg_last_error_msg() . ')');
        }

        $cited = array_values(array_unique(array_filter($matches['id'] ?? [], static fn(string $id): bool => $id !== '')));
        $unknown = array_values(array_diff($cited, $case->sources));

        if ($unknown !== []) {
            return Grade::fail('cites ids that are not sources of this case: ' . Text::excerpt(implode(', ', $unknown)));
        }

        if (count($cited) < $this->min) {
            return Grade::fail('cites ' . count($cited) . " of the required {$this->min}");
        }

        $missing = array_values(array_diff($case->sources, $cited));

        if ($this->all && $missing !== []) {
            return Grade::fail('does not cite: ' . Text::excerpt(implode(', ', $missing)));
        }

        return Grade::pass('cites ' . implode(', ', $cited));
    }
}
