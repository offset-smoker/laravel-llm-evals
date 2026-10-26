<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\Matches;

it('passes when the pattern matches', function (): void {
    expect((new Matches('/\bTuesday\b/'))->grade(reply('Closed Tuesday.'), evalCase())->passed)->toBeTrue();
});

it('fails when the pattern does not match', function (): void {
    expect((new Matches('/\bTuesday\b/'))->grade(reply('Closed Wednesday.'), evalCase())->passed)->toBeFalse();
});

it('inverts the result when negated', function (): void {
    $grader = new Matches('/\d{3}-\d{4}/', negate: true);

    expect($grader->grade(reply('Call the office.'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('Call 555-0100.'), evalCase())->passed)->toBeFalse();
});

it('rejects an invalid pattern when it is built', function (string $pattern): void {
    new Matches($pattern);
})->with(['no delimiters' => 'Tuesday', 'unbalanced' => '/(Tuesday/', 'empty' => ''])->throws(InvalidArgumentException::class);

it('fails the case instead of crashing when the engine gives up', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '10');
    ini_set('pcre.jit', '0');

    try {
        $grade = (new Matches('/(a+)+$/'))->grade(reply(str_repeat('a', 40) . 'b'), evalCase());
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', '1');
    }

    expect($grade->passed)->toBeFalse()->and($grade->reason)->toContain('could not be evaluated');
});
