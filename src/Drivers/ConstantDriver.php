<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;

/**
 * Returns the same text for every case. Useful as a falsifiability control:
 * a fixed answer should fail a dataset whose graders mean anything.
 */
final readonly class ConstantDriver implements Driver
{
    public function __construct(private string $text) {}

    public function name(): string
    {
        return 'constant';
    }

    public function respond(EvalCase $case, Dataset $dataset): Response
    {
        return new Response($this->text);
    }
}
