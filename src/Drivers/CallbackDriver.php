<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use Closure;
use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;

/**
 * Wraps a closure, usually one that calls your application's own LLM
 * feature, so that feature can be evaluated directly.
 */
final readonly class CallbackDriver implements Driver
{
    /**
     * @param  Closure(EvalCase, Dataset): (string|Response)  $callback
     */
    public function __construct(
        private Closure $callback,
        private string $name = 'callback',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function respond(EvalCase $case, Dataset $dataset): Response
    {
        $result = ($this->callback)($case, $dataset);

        return is_string($result) ? new Response($result) : $result;
    }
}
