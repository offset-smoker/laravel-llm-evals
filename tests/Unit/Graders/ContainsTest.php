<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\Contains;

it('passes when the text contains the needle, ignoring case by default', function (): void {
    $grade = (new Contains('elm street'))->grade(reply('Closed on Elm Street today.'), evalCase());

    expect($grade->passed)->toBeTrue()->and($grade->reason)->toBe('found');
});

it('fails when the needle is missing', function (): void {
    expect((new Contains('Elm Street'))->grade(reply('Closed on Oak Lane.'), evalCase())->passed)->toBeFalse();
});

it('respects case sensitivity when asked', function (): void {
    $grader = new Contains('Elm Street', caseSensitive: true);

    expect($grader->grade(reply('elm street'), evalCase())->passed)->toBeFalse()
        ->and($grader->grade(reply('Elm Street'), evalCase())->passed)->toBeTrue();
});

it('matches multibyte text without case sensitivity', function (): void {
    expect((new Contains('ÉCOLE'))->grade(reply('la petite école'), evalCase())->passed)->toBeTrue();
});

it('inverts the result when negated', function (): void {
    $grader = new Contains('as an AI', negate: true);

    expect($grader->grade(reply('Here is the summary.'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('As an AI, I think...'), evalCase())->passed)->toBeFalse()
        ->and($grader->describe())->toBe('does not contain "as an AI"');
});

it('never quotes the response in its reason', function (): void {
    $grade = (new Contains('needle'))->grade(reply('SECRET-OUTPUT-TEXT'), evalCase());

    expect($grade->reason)->not->toContain('SECRET-OUTPUT-TEXT');
});
