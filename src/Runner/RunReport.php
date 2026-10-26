<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Dataset\Dataset;

final readonly class RunReport
{
    public function __construct(
        public Dataset $dataset,
        public SuiteResult $control,
        public ?SuiteResult $subject,
    ) {}

    public function controlHeld(): bool
    {
        return $this->control->passRate() <= $this->dataset->control->ceiling;
    }

    public function verdict(): Verdict
    {
        if (! $this->controlHeld() || $this->subject === null) {
            return Verdict::ControlTooStrong;
        }

        return $this->subject->passRate() >= $this->dataset->threshold ? Verdict::Passed : Verdict::BelowThreshold;
    }
}
