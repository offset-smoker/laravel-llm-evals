<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Drivers\Driver;
use OffsetSmoker\LlmEvals\Drivers\DriverFailed;

/**
 * Runs a dataset's control first and, only if the control fails as it
 * should, runs the subject. A control that passes means the graders cannot
 * tell good output from bad, so the subject run (and its model spend) is
 * skipped.
 */
final readonly class EvalRunner
{
    public function run(Dataset $dataset, Driver $subject): RunReport
    {
        $control = $this->suite($dataset, $dataset->control->driver);

        if ($control->passRate() > $dataset->control->ceiling) {
            return new RunReport($dataset, $control, null);
        }

        return new RunReport($dataset, $control, $this->suite($dataset, $subject));
    }

    private function suite(Dataset $dataset, Driver $driver): SuiteResult
    {
        $results = [];

        foreach ($dataset->cases as $case) {
            try {
                $response = $driver->respond($case, $dataset);
            } catch (DriverFailed $e) {
                $results[] = CaseResult::errored($case->id, $e->getMessage());

                continue;
            }

            $graded = [];

            foreach ($case->graders as $grader) {
                $graded[] = new GraderResult($grader->describe(), $grader->grade($response, $case));
            }

            $results[] = CaseResult::graded($case->id, $response, $graded);
        }

        return new SuiteResult($driver->name(), $results);
    }
}
