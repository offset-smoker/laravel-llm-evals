<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Graders\Contains;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use PHPUnit\Framework\ExpectationFailedException;

it('passes a dataset with a driver', function (): void {
    expect(examplesPath('plain-language-notices.yaml'))->toPassEval(RecordedDriver::fromDirectory(examplesPath('recordings')));
});

it('fails with the failing cases and reasons when the subject is below threshold', function (): void {
    expect(examplesPath('plain-language-notices.yaml'))->toPassEval(static fn(EvalCase $case): string => 'Elm Street');
})->throws(ExpectationFailedException::class, 'Dataset "plain-language-notices" passed 0 of 3 cases, below its threshold of 1. Failed: water-main-closure (matches /Tuesday/: no match;');

it('explains a control that is too strong', function (): void {
    $file = scratchDirectory() . '/weak.yaml';
    file_put_contents($file, "name: weak\nthreshold: 0.5\ncontrol: {constant: ok, ceiling: 0.0}\ncases:\n  - {id: a, input: Say ok, expect: [{contains: ok}]}\n");

    expect($file)->toPassEval(static fn(): string => 'ok');
})->throws(ExpectationFailedException::class, 'the control (constant) passed 1 of 1 cases, above its ceiling of 0');

it('grades a single output against grader entries', function (): void {
    expect('The hearing is on March 3 in Room 2. [notice-214]')->toPassGraders([
        ['contains' => 'March 3'],
        ['citations' => true],
        ['reading_level' => 10],
        ['refusal' => false],
    ], ['notice-214']);
});

it('accepts a Response so stop reasons count', function (): void {
    expect(new Response('', 'refusal'))->toPassGraders([['refusal' => true]]);
});

it('lists every failing grader', function (): void {
    expect('Something else entirely.')->toPassGraders([['contains' => 'March 3'], ['regex' => '/Room \d/']]);
})->throws(ExpectationFailedException::class, 'The output failed 2 grader(s): contains "March 3": not found; matches /Room \d/: no match');

it('uses grader types registered on the container factory', function (): void {
    app(GraderFactory::class)->extend('mentions_township', static fn() => new Contains('Example Township'));

    expect('Example Township office hours')->toPassGraders([['mentions_township' => true]]);
});

it('rejects values it cannot grade', function (): void {
    expect(42)->toPassGraders([['contains' => 'x']]);
})->throws(ExpectationFailedException::class, 'toPassGraders() expects a string');
