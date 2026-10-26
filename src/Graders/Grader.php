<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;

/**
 * A deterministic, code-based check on one response.
 *
 * Reasons are printed in reports and CI logs, so a grader describes what it
 * expected and what it found without quoting the response at length.
 */
interface Grader
{
    /**
     * A short description of the expectation, such as `contains "Elm Street"`.
     */
    public function describe(): string;

    public function grade(Response $response, EvalCase $case): Grade;
}
