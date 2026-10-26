<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\ReadingLevel;
use OffsetSmoker\LlmEvals\Support\ReadabilityScore;

it('scores short plain sentences low and dense prose high', function (): void {
    $plain = ReadabilityScore::fleschKincaidGrade('The water is off. It comes back at noon. Call us with questions.');
    $dense = ReadabilityScore::fleschKincaidGrade('Pursuant to scheduled infrastructure remediation, the potable water distribution main shall be rendered temporarily inoperative, commencing at the designated hour, for a duration not anticipated to exceed six hours.');

    expect($plain)->toBeLessThan(3.0)->and($dense)->toBeGreaterThan(16.0);
});

it('computes the published formula on a hand-counted sentence', function (): void {
    // 6 words, 1 sentence, 6 syllables: 0.39 * 6 + 11.8 * 1 - 15.59.
    expect(ReadabilityScore::fleschKincaidGrade('The cat sat on the mat.'))->toEqualWithDelta(-1.45, 0.0001);
});

it('counts syllables with the usual English heuristics', function (string $word, int $syllables): void {
    expect(ReadabilityScore::syllables($word))->toBe($syllables);
})->with([
    ['the', 1], ['water', 2], ['closed', 1], ['makes', 1], ['table', 2],
    ['township', 2], ['hearing', 2], ['application', 4], ['2026', 1], ["resident's", 3],
]);

it('returns null for text with no words', function (): void {
    expect(ReadabilityScore::fleschKincaidGrade(' ... !!! '))->toBeNull();
});

it('passes at or below the maximum grade and fails above it', function (): void {
    $text = 'The water is off. It comes back at noon.';

    expect((new ReadingLevel(8))->grade(reply($text), evalCase())->passed)->toBeTrue()
        ->and((new ReadingLevel(-0.0))->grade(reply(str_repeat('Infrastructure remediation commencing. ', 3)), evalCase())->passed)->toBeFalse();
});

it('fails empty output instead of scoring it', function (): void {
    expect((new ReadingLevel(8))->grade(reply(''), evalCase())->reason)->toBe('no words to measure');
});

it('rejects an out-of-range maximum', function (): void {
    new ReadingLevel(31);
})->throws(InvalidArgumentException::class);
