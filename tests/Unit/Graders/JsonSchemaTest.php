<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Graders\JsonSchema;

function hearingSchema(): JsonSchema
{
    return new JsonSchema([
        'type' => 'object',
        'required' => ['event', 'date'],
        'additionalProperties' => false,
        'properties' => [
            'event' => ['type' => 'string', 'minLength' => 1],
            'date' => ['type' => 'string'],
        ],
    ]);
}

it('passes valid JSON that matches the schema', function (): void {
    expect(hearingSchema()->grade(reply('{"event": "Hearing", "date": "March 3"}'), evalCase())->passed)->toBeTrue();
});

it('accepts one surrounding Markdown code fence', function (): void {
    $text = "```json\n{\"event\": \"Hearing\", \"date\": \"March 3\"}\n```";

    expect(hearingSchema()->grade(reply($text), evalCase())->passed)->toBeTrue();
});

it('fails text that is not JSON', function (): void {
    $grade = hearingSchema()->grade(reply('Sure! Here is the JSON you asked for.'), evalCase());

    expect($grade->passed)->toBeFalse()->and($grade->reason)->toStartWith('not valid JSON');
});

it('reports schema violations with their JSON pointer', function (): void {
    $grade = hearingSchema()->grade(reply('{"event": "", "extra": true}'), evalCase());

    expect($grade->passed)->toBeFalse()->and($grade->reason)->toStartWith('schema violations:');
});

it('keeps empty maps as objects so an empty properties keyword stays valid', function (): void {
    $grader = new JsonSchema(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);

    expect($grader->grade(reply('{}'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('{"a": 1}'), evalCase())->passed)->toBeFalse();
});

it('rejects an unusable schema when it is built', function (): void {
    new JsonSchema(['type' => 'not-a-type']);
})->throws(InvalidArgumentException::class, 'is not a usable JSON Schema');

it('never resolves a $ref over the network or from the filesystem', function (string $reference): void {
    new JsonSchema(['$ref' => $reference]);
})->with([
    'http' => 'http://127.0.0.1:9/remote-schema.json',
    'file' => 'file:///etc/passwd',
])->throws(InvalidArgumentException::class, 'Unresolved reference');
