<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Support\Text;

/**
 * Passes when the response contains (or, negated, does not contain) a
 * substring. Matching ignores case unless `caseSensitive` is set.
 */
final readonly class Contains implements Grader
{
    public function __construct(
        private string $needle,
        private bool $caseSensitive = false,
        private bool $negate = false,
    ) {}

    public function describe(): string
    {
        $verb = $this->negate ? 'does not contain' : 'contains';

        return "{$verb} \"" . Text::excerpt($this->needle) . '"';
    }

    public function grade(Response $response, EvalCase $case): Grade
    {
        $found = $this->caseSensitive
            ? str_contains($response->text, $this->needle)
            : mb_stripos($response->text, $this->needle) !== false;

        if ($found === ! $this->negate) {
            return Grade::pass($this->negate ? 'not found, as expected' : 'found');
        }

        return Grade::fail($this->negate ? 'found text that must not appear' : 'not found');
    }
}
