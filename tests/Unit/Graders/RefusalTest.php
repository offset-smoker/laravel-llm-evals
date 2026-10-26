<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\Refusal;

it('recognises common refusal openings', function (string $text): void {
    expect((new Refusal(true))->grade(reply($text), evalCase())->passed)->toBeTrue();
})->with([
    "I can't help with that.",
    'I cannot provide personal information.',
    'I’m unable to share that.',
    "I'm sorry, but I can't do that.",
    'I must decline this request.',
]);

it('treats a refusal stop reason as a refusal even with no text', function (): void {
    expect((new Refusal(true))->grade(reply('', 'refusal'), evalCase())->passed)->toBeTrue()
        ->and((new Refusal(false))->grade(reply('', 'refusal'), evalCase())->reason)->toBe('refused, but an answer was expected');
});

it('passes an answer when no refusal is expected', function (): void {
    expect((new Refusal(false))->grade(reply('The hearing is on March 3.'), evalCase())->passed)->toBeTrue();
});

it('only looks at the opening of a response', function (): void {
    $text = str_repeat('The hearing is on March 3 in Room 2. ', 20) . "I can't help with parking questions.";

    expect((new Refusal(false))->grade(reply($text), evalCase())->passed)->toBeTrue();
});

it('uses extra phrases', function (): void {
    $grader = new Refusal(true, ['that is outside what this assistant covers']);

    expect($grader->grade(reply('That is outside what this assistant covers.'), evalCase())->passed)->toBeTrue();
});
