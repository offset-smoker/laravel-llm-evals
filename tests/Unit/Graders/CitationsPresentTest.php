<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\CitationsPresent;

it('passes when the response cites a source of the case', function (): void {
    $grade = (new CitationsPresent())->grade(reply('Closed Tuesday. [notice-101]'), evalCase(sources: ['notice-101']));

    expect($grade->passed)->toBeTrue()->and($grade->reason)->toBe('cites notice-101');
});

it('fails when nothing is cited', function (): void {
    $grade = (new CitationsPresent())->grade(reply('Closed Tuesday.'), evalCase(sources: ['notice-101']));

    expect($grade->passed)->toBeFalse()->and($grade->reason)->toBe('cites 0 of the required 1');
});

it('fails an invented citation even when a real one is present', function (): void {
    $grade = (new CitationsPresent())->grade(reply('[notice-101] and [notice-999]'), evalCase(sources: ['notice-101']));

    expect($grade->passed)->toBeFalse()->and($grade->reason)->toContain('notice-999');
});

it('counts each source once', function (): void {
    $grade = (new CitationsPresent(min: 2))->grade(reply('[notice-101] [notice-101]'), evalCase(sources: ['notice-101', 'notice-102']));

    expect($grade->passed)->toBeFalse();
});

it('requires every source with all', function (): void {
    $grader = new CitationsPresent(all: true);
    $case = evalCase(sources: ['notice-101', 'notice-102']);

    expect($grader->grade(reply('[notice-101]'), $case)->passed)->toBeFalse()
        ->and($grader->grade(reply('[notice-101] [notice-102]'), $case)->passed)->toBeTrue();
});

it('accepts a custom pattern with a named id group', function (): void {
    $grader = new CitationsPresent(pattern: '/\(source: (?<id>[a-z0-9-]+)\)/');

    expect($grader->grade(reply('Closed (source: notice-101).'), evalCase(sources: ['notice-101']))->passed)->toBeTrue();
});

it('rejects a pattern without an id group or a minimum below one', function (int $min, string $pattern): void {
    new CitationsPresent(min: $min, pattern: $pattern);
})->with([
    'no id group' => [1, '/\[(.+?)\]/'],
    'invalid pattern' => [1, '/(?<id>[/'],
    'zero minimum' => [0, CitationsPresent::DEFAULT_PATTERN],
])->throws(InvalidArgumentException::class);
