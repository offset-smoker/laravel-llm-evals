<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Drivers\Response;

/**
 * The outcome of one case: the response and each grader's verdict, or the
 * driver error that prevented a response. An errored case counts as failed.
 */
final readonly class CaseResult
{
    /**
     * @param  list<GraderResult>  $graderResults
     */
    private function __construct(
        public string $caseId,
        public ?Response $response,
        public array $graderResults,
        public ?string $error,
    ) {}

    /**
     * @param  list<GraderResult>  $graderResults
     */
    public static function graded(string $caseId, Response $response, array $graderResults): self
    {
        return new self($caseId, $response, $graderResults, null);
    }

    public static function errored(string $caseId, string $error): self
    {
        return new self($caseId, null, [], $error);
    }

    public function passed(): bool
    {
        if ($this->error !== null) {
            return false;
        }

        foreach ($this->graderResults as $result) {
            if (! $result->grade->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fraction of graders passed, from 0 to 1. An errored case scores 0.
     */
    public function score(): float
    {
        if ($this->error !== null || $this->graderResults === []) {
            return 0.0;
        }

        $passed = count(array_filter($this->graderResults, static fn(GraderResult $result): bool => $result->grade->passed));

        return $passed / count($this->graderResults);
    }
}
