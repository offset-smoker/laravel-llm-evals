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

it('rejects a non-local reference anywhere in the schema when it is built', function (array $schema, string $where): void {
    expect(fn() => new JsonSchema($schema))->toThrow(InvalidArgumentException::class, "non-local \$ref at {$where}");
})->with([
    'top level http' => [['$ref' => 'http://127.0.0.1:9/remote-schema.json'], '#;'],
    'nested file' => [['type' => 'object', 'properties' => ['a' => ['$ref' => 'file:///etc/passwd']]], '#/properties/a;'],
    'inside allOf' => [['allOf' => [['type' => 'object'], ['$ref' => 'other.json']]], '#/allOf/1;'],
    'under $defs' => [['$defs' => ['x' => ['items' => ['$ref' => 'https://example.org/s.json']]]], '#/$defs/x/items;'],
    'under draft-07 dependencies' => [['dependencies' => ['a' => ['$ref' => 'file:///etc/hosts']]], '#/dependencies/a;'],
    'under dependencies, over http' => [['dependencies' => ['a' => ['$ref' => 'http://127.0.0.1:9/x.json']]], '#/dependencies/a;'],
    'under a keyword the walker does not know' => [['x-vendor' => ['nested' => ['$ref' => 'file:///etc/hosts']]], '#/x-vendor/nested;'],
]);

it('keeps the string-list form of draft-07 dependencies', function (): void {
    $grader = new JsonSchema(['type' => 'object', 'dependencies' => ['a' => ['b'], 'c' => ['required' => ['d']]]]);

    expect($grader->grade(reply('{"a": 1, "b": 2, "c": 3, "d": 4}'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('{"a": 1}'), evalCase())->passed)->toBeFalse()
        ->and($grader->grade(reply('{"c": 1}'), evalCase())->passed)->toBeFalse();
});

it('rejects an $id below the root, which would re-base local references', function (): void {
    new JsonSchema(['$defs' => ['n' => ['type' => 'integer']], 'properties' => ['x' => ['$id' => 'http://127.0.0.1:9/x.json', 'properties' => ['y' => ['$ref' => '#/$defs/n']]]]]);
})->throws(InvalidArgumentException::class, 'uses $id at #/properties/x;');

it('allows an $id at the root, where local references still resolve', function (): void {
    $grader = new JsonSchema(['$id' => 'https://example.org/notice.schema.json', '$defs' => ['n' => ['type' => 'integer']], 'type' => 'object', 'properties' => ['x' => ['$ref' => '#/$defs/n']]]);

    expect($grader->grade(reply('{"x": 1}'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('{"x": "one"}'), evalCase())->passed)->toBeFalse();
});

it('does not honour the validator\'s non-standard extension keywords', function (): void {
    // With Opis extensions on, $filters calls a registered PHP function; an unknown one makes the schema unusable.
    $grader = new JsonSchema(['type' => 'string', '$filters' => ['$func' => 'not-registered']]);

    expect($grader->grade(reply('"text"'), evalCase())->passed)->toBeTrue();
});

it('allows local references', function (): void {
    $grader = new JsonSchema(['$defs' => ['date' => ['type' => 'string', 'pattern' => 'March']], 'type' => 'object', 'properties' => ['date' => ['$ref' => '#/$defs/date']]]);

    expect($grader->grade(reply('{"date": "March 3"}'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('{"date": "April 1"}'), evalCase())->reason)->toBe('schema violations: pattern at #/$defs/date');
});

it('treats empty sub-schemas as the schema that accepts anything', function (): void {
    $grader = new JsonSchema(['type' => 'object', 'properties' => ['a' => []], 'items' => [], 'additionalProperties' => false]);

    expect($grader->grade(reply('{"a": {"anything": [1, 2]}}'), evalCase())->passed)->toBeTrue()
        ->and($grader->grade(reply('{"b": 1}'), evalCase())->passed)->toBeFalse();
});

it('never quotes keys or values from the response in its reasons', function (): void {
    $grade = hearingSchema()->grade(reply('{"event": "Hearing", "date": "March 3", "CANARY-KEY-SSN-123": "CANARY-VALUE"}'), evalCase());

    expect($grade->passed)->toBeFalse()
        ->and($grade->reason)->toBe('schema violations: additionalProperties at #')
        ->and($grade->reason)->not->toContain('CANARY');
});
