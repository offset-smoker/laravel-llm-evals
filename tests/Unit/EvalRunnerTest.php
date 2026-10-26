<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Dataset\DatasetLoader;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\CallbackDriver;
use OffsetSmoker\LlmEvals\Drivers\DriverFailed;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Drivers\Usage;
use OffsetSmoker\LlmEvals\Graders\Contains;
use OffsetSmoker\LlmEvals\Runner\EvalRunner;
use OffsetSmoker\LlmEvals\Runner\Verdict;

/**
 * @return list<EvalCase>
 */
function fourCases(): array
{
    return array_map(
        static fn(int $n): EvalCase => new EvalCase("case-{$n}", "Question {$n}", [], [new Contains("answer {$n}"), new Contains('please')]),
        [1, 2, 3, 4],
    );
}

function answering(int ...$correct): CallbackDriver
{
    return new CallbackDriver(static function (EvalCase $case) use ($correct): string {
        $n = (int) substr($case->id, 5);

        return in_array($n, $correct, true) ? "answer {$n}, please" : 'please';
    }, 'subject');
}

it('passes when the subject meets the threshold and the control holds', function (): void {
    $report = (new EvalRunner())->run(makeDataset(fourCases()), answering(1, 2, 3, 4));

    expect($report->verdict())->toBe(Verdict::Passed)
        ->and($report->controlHeld())->toBeTrue()
        ->and($report->subject?->passRate())->toBe(1.0)
        ->and($report->subject?->driver)->toBe('subject');
});

it('passes exactly at the threshold and fails just below it', function (): void {
    $runner = new EvalRunner();

    expect($runner->run(makeDataset(fourCases(), threshold: 0.75), answering(1, 2, 3))->verdict())->toBe(Verdict::Passed)
        ->and($runner->run(makeDataset(fourCases(), threshold: 0.75), answering(1, 2))->verdict())->toBe(Verdict::BelowThreshold);
});

it('scores partial cases by the fraction of graders passed', function (): void {
    $subject = (new EvalRunner())->run(makeDataset(fourCases(), threshold: 0.5), answering(1, 2))->subject;

    expect($subject?->passedCount())->toBe(2)
        ->and($subject?->meanScore())->toBe(0.75)
        ->and($subject?->cases[2]->score())->toBe(0.5);
});

it('fails the run and never calls the subject when the control is too strong', function (): void {
    $called = false;
    $subject = new CallbackDriver(static function () use (&$called): string {
        $called = true;

        return 'answer 1, please';
    });

    // The control's fixed text satisfies every grader of case 1.
    $report = (new EvalRunner())->run(makeDataset(fourCases(), threshold: 0.75, ceiling: 0.0, control: 'answer 1, please'), $subject);

    expect($report->verdict())->toBe(Verdict::ControlTooStrong)
        ->and($report->controlHeld())->toBeFalse()
        ->and($report->subject)->toBeNull()
        ->and($called)->toBeFalse()
        ->and($report->control->passRate())->toBe(0.25);
});

it('allows the control up to its ceiling', function (): void {
    $report = (new EvalRunner())->run(makeDataset(fourCases(), threshold: 0.75, ceiling: 0.25, control: 'answer 1, please'), answering(1, 2, 3, 4));

    expect($report->verdict())->toBe(Verdict::Passed);
});

it('records a driver failure as a failed case and keeps going', function (): void {
    $subject = new CallbackDriver(static function (EvalCase $case): string {
        if ($case->id === 'case-2') {
            throw new DriverFailed('rate limited');
        }

        return 'answer ' . substr($case->id, 5) . ', please';
    });

    $suite = (new EvalRunner())->run(makeDataset(fourCases(), threshold: 0.75), $subject)->subject;

    expect($suite?->erroredCount())->toBe(1)
        ->and($suite?->passedCount())->toBe(3)
        ->and($suite?->cases[1]->error)->toBe('rate limited')
        ->and($suite?->cases[1]->passed())->toBeFalse()
        ->and($suite?->cases[1]->score())->toBe(0.0);
});

it('sums token usage over the subject run', function (): void {
    $subject = new CallbackDriver(static fn(EvalCase $case): Response => new Response('answer, please', 'end_turn', 'm', new Usage(10, 5, 100, 1)));

    $usage = (new EvalRunner())->run(makeDataset(fourCases(), threshold: 0.25), $subject)->subject?->usage();

    expect($usage?->toArray())->toBe(['input_tokens' => 40, 'output_tokens' => 20, 'cache_read_input_tokens' => 400, 'cache_creation_input_tokens' => 4]);
});

it('passes the shipped example with its recording and fails its control', function (): void {
    $dataset = (new DatasetLoader())->load(examplesPath('plain-language-notices.yaml'));

    $report = (new EvalRunner())->run($dataset, RecordedDriver::fromDirectory(examplesPath('recordings')));

    expect($report->verdict())->toBe(Verdict::Passed)
        ->and($report->control->passedCount())->toBe(1)
        ->and($report->subject?->passedCount())->toBe(3);
});

it('fails the shipped example when the subject just echoes the input', function (): void {
    $dataset = (new DatasetLoader())->load(examplesPath('plain-language-notices.yaml'));

    $report = (new EvalRunner())->run($dataset, new CallbackDriver(static fn(EvalCase $case): string => $case->input));

    expect($report->verdict())->toBe(Verdict::BelowThreshold)
        ->and($report->subject?->passedCount())->toBe(0);
});

it('refuses to treat an errored control as a control that held', function (): void {
    $dataset = new OffsetSmoker\LlmEvals\Dataset\Dataset('test-set', 1.0, null, new OffsetSmoker\LlmEvals\Dataset\Control(
        new CallbackDriver(static fn(): string => throw new DriverFailed('no recording')),
        0.0,
    ), fourCases(), '/datasets/test-set.yaml');

    (new EvalRunner())->run($dataset, answering(1, 2, 3, 4));
})->throws(OffsetSmoker\LlmEvals\Dataset\InvalidDataset::class, 'the control could not produce output for 4 case(s)');

it('fails a grader that throws instead of crashing the run', function (): void {
    $grader = new class implements OffsetSmoker\LlmEvals\Graders\Grader {
        public function describe(): string
        {
            return 'explodes';
        }

        public function grade(Response $response, EvalCase $case): OffsetSmoker\LlmEvals\Graders\Grade
        {
            throw new RuntimeException("contains {$response->text}");
        }
    };

    $case = new EvalCase('case-1', 'in', [], [$grader]);
    $result = (new EvalRunner())->run(makeDataset([$case], threshold: 1.0), new CallbackDriver(static fn(): string => 'SECRET'))->subject?->cases[0];

    expect($result?->passed())->toBeFalse()
        ->and($result?->graderResults[0]->grade->reason)->toStartWith('the grader threw RuntimeException at EvalRunnerTest.php:')
        ->and($result?->graderResults[0]->grade->reason)->not->toContain('SECRET');
});
