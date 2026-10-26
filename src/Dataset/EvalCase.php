<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

use OffsetSmoker\LlmEvals\Graders\Grader;

/**
 * One golden case: the input sent to the subject, the source ids the output
 * may cite, and the graders its output must pass.
 */
final readonly class EvalCase
{
    /**
     * @param  list<string>  $sources
     * @param  list<Grader>  $graders
     */
    public function __construct(
        public string $id,
        public string $input,
        public array $sources,
        public array $graders,
    ) {}
}
