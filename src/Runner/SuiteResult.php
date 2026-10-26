<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Drivers\Usage;

/**
 * Every case of one dataset run through one driver.
 */
final readonly class SuiteResult
{
    /**
     * @param  list<CaseResult>  $cases
     */
    public function __construct(
        public string $driver,
        public array $cases,
    ) {}

    public function passedCount(): int
    {
        return count(array_filter($this->cases, static fn(CaseResult $case): bool => $case->passed()));
    }

    public function erroredCount(): int
    {
        return count(array_filter($this->cases, static fn(CaseResult $case): bool => $case->error !== null));
    }

    public function passRate(): float
    {
        return $this->cases === [] ? 0.0 : $this->passedCount() / count($this->cases);
    }

    public function meanScore(): float
    {
        if ($this->cases === []) {
            return 0.0;
        }

        return array_sum(array_map(static fn(CaseResult $case): float => $case->score(), $this->cases)) / count($this->cases);
    }

    public function usage(): Usage
    {
        $usage = new Usage();

        foreach ($this->cases as $case) {
            if ($case->response !== null) {
                $usage = $usage->plus($case->response->usage);
            }
        }

        return $usage;
    }

    /**
     * The responses of the cases that produced one, keyed by case id.
     *
     * @return array<string, Response>
     */
    public function responses(): array
    {
        $responses = [];

        foreach ($this->cases as $case) {
            if ($case->response !== null) {
                $responses[$case->caseId] = $case->response;
            }
        }

        return $responses;
    }
}
