<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Runner;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;
use OffsetSmoker\LlmEvals\Drivers\Driver;
use OffsetSmoker\LlmEvals\Drivers\DriverFailed;
use OffsetSmoker\LlmEvals\Graders\Grade;
use Throwable;

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

        // An errored control case would count as "failed", which is exactly what a control is
        // supposed to do; a control that could not run proves nothing, so it is a setup error.
        if ($control->erroredCount() > 0) {
            throw InvalidDataset::file($dataset->file, "the control could not produce output for {$control->erroredCount()} case(s), so it proves nothing about the graders.");
        }

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
            } catch (InvalidDataset $e) {
                throw $e;
            } catch (Throwable $e) {
                // Any other exception's message may quote the prompt, so only its type and origin are kept.
                $results[] = CaseResult::errored($case->id, sprintf('The driver threw %s at %s:%d.', $e::class, basename($e->getFile()), $e->getLine()));

                continue;
            }

            $graded = [];

            foreach ($case->graders as $grader) {
                try {
                    $grade = $grader->grade($response, $case);
                } catch (Throwable $e) {
                    $grade = Grade::fail(sprintf('the grader threw %s at %s:%d', $e::class, basename($e->getFile()), $e->getLine()));
                }

                $graded[] = new GraderResult($grader->describe(), $grade);
            }

            $results[] = CaseResult::graded($case->id, $response, $graded);
        }

        return new SuiteResult($driver->name(), $results);
    }
}
