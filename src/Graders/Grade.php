<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

final readonly class Grade
{
    private function __construct(
        public bool $passed,
        public string $reason,
    ) {}

    public static function pass(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
