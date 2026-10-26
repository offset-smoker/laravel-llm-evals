<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

/**
 * The outcome of a run. The backing value is the process exit code the
 * `evals:run` command returns for it.
 */
enum Verdict: int
{
    case Passed = 0;

    /** The subject's pass rate is below the dataset threshold. */
    case BelowThreshold = 1;

    /** The control passed more cases than its ceiling allows, so the graders cannot be trusted and the subject was not run. */
    case ControlTooStrong = 2;

    /** The dataset, a recording, an argument or the configuration is invalid. Only the command returns this. */
    case InvalidInput = 3;

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'passed',
            self::BelowThreshold => 'below threshold',
            self::ControlTooStrong => 'control too strong',
            self::InvalidInput => 'invalid input',
        };
    }
}
