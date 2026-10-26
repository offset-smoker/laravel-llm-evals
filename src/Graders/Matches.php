<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use InvalidArgumentException;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Support\Text;

/**
 * Passes when a PCRE pattern matches the response (or, negated, does not).
 * The pattern is checked when the grader is built, so a typo fails the
 * dataset load instead of every case.
 */
final readonly class Matches implements Grader
{
    public function __construct(
        private string $pattern,
        private bool $negate = false,
    ) {
        if (Text::safePregMatch($this->pattern, '') === null) {
            throw new InvalidArgumentException('is not a valid PCRE pattern (include the delimiters, for example "/Tuesday/i").');
        }
    }

    public function describe(): string
    {
        return ($this->negate ? 'does not match ' : 'matches ') . Text::excerpt($this->pattern);
    }

    public function grade(Response $response, EvalCase $case): Grade
    {
        $matched = Text::safePregMatch($this->pattern, $response->text);

        if ($matched === null) {
            return Grade::fail('the pattern could not be evaluated (' . preg_last_error_msg() . ')');
        }

        if ($matched === ! $this->negate) {
            return Grade::pass($this->negate ? 'no match, as expected' : 'matched');
        }

        return Grade::fail($this->negate ? 'matched a pattern that must not match' : 'no match');
    }
}
