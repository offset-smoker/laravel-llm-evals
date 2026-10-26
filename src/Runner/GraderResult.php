<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Graders\Grade;

final readonly class GraderResult
{
    public function __construct(
        public string $grader,
        public Grade $grade,
    ) {}
}
