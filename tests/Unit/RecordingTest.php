<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;
use OffsetSmoker\LlmEvals\Drivers\DriverFailed;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Drivers\Recording;
use OffsetSmoker\LlmEvals\Drivers\Response;

it('round-trips responses through a recording file', function (): void {
    $file = scratchDirectory() . '/nested/dir/test-set.json';

    Recording::write($file, 'test-set', [
        'case-1' => new Response("Line one\nLine two — ünïcode", 'end_turn', 'claude-opus-5-5'),
        'case-2' => new Response('', 'refusal'),
    ]);

    $read = Recording::read($file, 'test-set');

    expect($read['case-1']->text)->toBe("Line one\nLine two — ünïcode")
        ->and($read['case-1']->model)->toBe('claude-opus-5-5')
        ->and($read['case-2']->stopReason)->toBe('refusal')
        ->and(glob(dirname($file) . '/*'))->toBe([$file]);
});

it('writes an empty responses map as an object', function (): void {
    $file = scratchDirectory() . '/empty.json';
    Recording::write($file, 'empty', []);

    expect((string) file_get_contents($file))->toContain('"responses": {}')
        ->and(Recording::read($file, 'empty'))->toBe([]);
});

it('replays by dataset name from a directory', function (): void {
    $directory = scratchDirectory();
    Recording::write("{$directory}/test-set.json", 'test-set', ['case-1' => new Response('saved')]);

    $driver = RecordedDriver::fromDirectory($directory);

    expect($driver->respond(evalCase(), makeDataset([evalCase()]))->text)->toBe('saved');
});

it('fails loudly for a case with no saved response', function (): void {
    $directory = scratchDirectory();
    Recording::write("{$directory}/test-set.json", 'test-set', []);

    RecordedDriver::fromDirectory($directory)->respond(evalCase(), makeDataset([evalCase()]));
})->throws(DriverFailed::class, 'has no response for case "case-1"');

it('rejects a recording made for another dataset', function (): void {
    $file = scratchDirectory() . '/other.json';
    Recording::write($file, 'other-set', []);

    RecordedDriver::fromFile($file)->respond(evalCase(), makeDataset([evalCase()]));
})->throws(InvalidDataset::class, 'dataset must be "test-set"');

it('rejects malformed recordings', function (string $json, string $message): void {
    $file = scratchDirectory() . '/bad.json';
    file_put_contents($file, $json);

    expect(fn() => Recording::read($file, 'test-set'))->toThrow(InvalidDataset::class, $message);
})->with([
    'not json' => ['{', 'recording is not valid JSON'],
    'missing responses' => ['{"dataset": "test-set"}', 'responses must be a map'],
    'text not a string' => ['{"dataset": "test-set", "responses": {"a": {"text": 5}}}', 'responses.a.text must be a string'],
    'unknown key' => ['{"dataset": "test-set", "responses": {"a": {"text": "x", "score": 1}}}', 'responses.a.score is not a recognised key'],
]);

it('reports a missing recording file as invalid input', function (): void {
    Recording::read('/nonexistent/recording.json', 'test-set');
})->throws(InvalidDataset::class, 'recording not found');

it('can be used as a closure-free fake for any case', function (): void {
    $file = scratchDirectory() . '/test-set.json';
    Recording::write($file, 'test-set', ['other' => new Response('x')]);
    $case = new EvalCase('other', 'in', [], []);

    expect(RecordedDriver::fromFile($file)->respond($case, makeDataset([$case]))->text)->toBe('x');
});
