<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

/**
 * Token counts reported by a model provider for one response, or summed
 * over a run.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $cacheCreationInputTokens = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadInputTokens + $other->cacheReadInputTokens,
            $this->cacheCreationInputTokens + $other->cacheCreationInputTokens,
        );
    }

    public function isEmpty(): bool
    {
        return $this->inputTokens === 0
            && $this->outputTokens === 0
            && $this->cacheReadInputTokens === 0
            && $this->cacheCreationInputTokens === 0;
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int, cache_creation_input_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
        ];
    }
}
