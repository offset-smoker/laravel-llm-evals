<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;

/**
 * Returns each case's input unchanged. As a control it catches graders that
 * a model could satisfy by repeating the question back.
 */
final readonly class EchoDriver implements Driver
{
    public function name(): string
    {
        return 'echo';
    }

    public function respond(EvalCase $case, Dataset $dataset): Response
    {
        return new Response($case->input);
    }
}
