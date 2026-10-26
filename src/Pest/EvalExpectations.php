<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Pest;

use Closure;
use OffsetSmoker\LlmEvals\Dataset\DatasetLoader;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\CallbackDriver;
use OffsetSmoker\LlmEvals\Drivers\Driver;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\Runner\EvalRunner;
use OffsetSmoker\LlmEvals\Runner\RunReport;
use OffsetSmoker\LlmEvals\Runner\Verdict;
use Pest\Expectation;
use PHPUnit\Framework\Assert;

/**
 * Pest expectations:
 *
 *     expect('evals/notices.yaml')->toPassEval($driver);
 *     expect($summary)->toPassGraders([['contains' => 'Elm Street'], ['reading_level' => 8]]);
 */
final class EvalExpectations
{
    public static function register(): void
    {
        (new Expectation(null))->extend('toPassEval', function (Driver|Closure $driver): Expectation {
            EvalExpectations::assertPassesEval($this->value, $driver);

            return $this;
        });

        (new Expectation(null))->extend('toPassGraders', function (array $graders, array $sources = []): Expectation {
            EvalExpectations::assertPassesGraders($this->value, $graders, $sources);

            return $this;
        });
    }

    /**
     * @param  Driver|Closure(EvalCase, \OffsetSmoker\LlmEvals\Dataset\Dataset): (string|Response)  $driver
     */
    public static function assertPassesEval(mixed $file, Driver|Closure $driver): RunReport
    {
        Assert::assertIsString($file, 'toPassEval() expects the path of a dataset file.');

        $subject = $driver instanceof Closure ? new CallbackDriver($driver) : $driver;
        $report = (new EvalRunner())->run((new DatasetLoader(self::factory()))->load($file), $subject);

        Assert::assertSame(Verdict::Passed, $report->verdict(), self::explain($report));

        return $report;
    }

    /**
     * @param  array<array-key, mixed>  $graders
     * @param  array<array-key, mixed>  $sources
     */
    public static function assertPassesGraders(mixed $output, array $graders, array $sources = []): void
    {
        $text = $output instanceof Response ? $output->text : $output;
        Assert::assertIsString($text, 'toPassGraders() expects a string or a ' . Response::class . '.');

        $sourceIds = array_values(array_filter($sources, is_string(...)));
        Assert::assertCount(count($sources), $sourceIds, 'toPassGraders() sources must be strings.');

        $response = $output instanceof Response ? $output : new Response($text);
        $case = new EvalCase('expectation', '', $sourceIds, (new DatasetLoader(self::factory()))->graders(array_values($graders), 'toPassGraders()'));
        $failures = [];

        foreach ($case->graders as $grader) {
            $grade = $grader->grade($response, $case);

            if (! $grade->passed) {
                $failures[] = "{$grader->describe()}: {$grade->reason}";
            }
        }

        Assert::assertSame([], $failures, 'The output failed ' . count($failures) . ' grader(s): ' . implode('; ', $failures));
    }

    private static function factory(): GraderFactory
    {
        if (function_exists('app') && app()->bound(GraderFactory::class)) {
            return app(GraderFactory::class);
        }

        return new GraderFactory();
    }

    private static function explain(RunReport $report): string
    {
        $verdict = $report->verdict();

        if ($verdict === Verdict::ControlTooStrong) {
            return sprintf(
                'Dataset "%s": the control (%s) passed %d of %d cases, above its ceiling of %s, so the graders cannot tell good output from bad.',
                $report->dataset->name,
                $report->control->driver,
                $report->control->passedCount(),
                count($report->control->cases),
                $report->dataset->control->ceiling,
            );
        }

        $failed = [];

        foreach ($report->subject->cases ?? [] as $case) {
            if ($case->passed()) {
                continue;
            }

            $reasons = $case->error === null ? [] : [$case->error];

            foreach ($case->graderResults as $graded) {
                if (! $graded->grade->passed) {
                    $reasons[] = "{$graded->grader}: {$graded->grade->reason}";
                }
            }

            $failed[] = "{$case->caseId} (" . implode('; ', $reasons) . ')';
        }

        return sprintf(
            'Dataset "%s" passed %d of %d cases, below its threshold of %s. Failed: %s',
            $report->dataset->name,
            $report->subject?->passedCount() ?? 0,
            count($report->dataset->cases),
            $report->dataset->threshold,
            implode(', ', $failed),
        );
    }
}
