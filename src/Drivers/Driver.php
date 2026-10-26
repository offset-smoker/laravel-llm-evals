<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;

/**
 * Produces the output that graders score: a model call, a recording, a
 * fixed baseline, or your application's own LLM feature.
 */
interface Driver
{
    /**
     * A short label for reports, such as "claude:claude-opus-5-5".
     */
    public function name(): string;

    /**
     * @throws DriverFailed when no output could be produced for this case
     */
    public function respond(EvalCase $case, Dataset $dataset): Response;
}
