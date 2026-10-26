<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Reports;

use OffsetSmoker\LlmEvals\Runner\CaseResult;
use OffsetSmoker\LlmEvals\Runner\RunReport;
use OffsetSmoker\LlmEvals\Runner\SuiteResult;

/**
 * A machine-readable report for CI artifacts. Case inputs and the system
 * prompt are never included; model outputs only when asked for.
 */
final class JsonReport
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RunReport $report, bool $withOutputs = false): array
    {
        return [
            'dataset' => $report->dataset->name,
            'verdict' => $report->verdict()->label(),
            'exit_code' => $report->verdict()->value,
            'threshold' => $report->dataset->threshold,
            'control' => [
                'ceiling' => $report->dataset->control->ceiling,
                'held' => $report->controlHeld(),
                ...self::suite($report->control, $withOutputs),
            ],
            'subject' => $report->subject === null ? null : self::suite($report->subject, $withOutputs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function suite(SuiteResult $suite, bool $withOutputs): array
    {
        return [
            'driver' => $suite->driver,
            'cases' => count($suite->cases),
            'passed' => $suite->passedCount(),
            'errored' => $suite->erroredCount(),
            'pass_rate' => round($suite->passRate(), 4),
            'mean_score' => round($suite->meanScore(), 4),
            'usage' => $suite->usage()->toArray(),
            'results' => array_map(static fn(CaseResult $case): array => self::case($case, $withOutputs), $suite->cases),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function case(CaseResult $case, bool $withOutputs): array
    {
        $result = [
            'id' => $case->caseId,
            'passed' => $case->passed(),
            'score' => round($case->score(), 4),
            'error' => $case->error,
            'stop_reason' => $case->response?->stopReason,
            'graders' => array_map(static fn($graded): array => [
                'grader' => $graded->grader,
                'passed' => $graded->grade->passed,
                'reason' => $graded->grade->reason,
            ], $case->graderResults),
        ];

        if ($withOutputs) {
            $result['output'] = $case->response?->text;
        }

        return $result;
    }
}
