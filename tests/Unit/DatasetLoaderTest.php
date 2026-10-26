<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Dataset\DatasetLoader;
use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;
use OffsetSmoker\LlmEvals\Drivers\ConstantDriver;
use OffsetSmoker\LlmEvals\Drivers\EchoDriver;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Graders\CitationsPresent;
use OffsetSmoker\LlmEvals\Graders\Contains;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\Graders\JsonSchema;
use OffsetSmoker\LlmEvals\Graders\Matches;
use OffsetSmoker\LlmEvals\Graders\ReadingLevel;
use OffsetSmoker\LlmEvals\Graders\Refusal;

function writeDataset(string $contents, string $extension = 'yaml'): string
{
    $file = scratchDirectory() . "/dataset.{$extension}";
    file_put_contents($file, $contents);

    return $file;
}

const MINIMAL = <<<'YAML'
name: minimal
threshold: 0.5
control: {constant: "nothing", ceiling: 0.0}
cases:
  - id: one
    input: Say hello.
    expect:
      - contains: hello
YAML;

it('loads the shipped example dataset with every built-in grader type', function (): void {
    $dataset = (new DatasetLoader())->load(examplesPath('plain-language-notices.yaml'));

    expect($dataset->name)->toBe('plain-language-notices')
        ->and($dataset->threshold)->toBe(1.0)
        ->and($dataset->system)->toContain('Example Township')
        ->and($dataset->control->driver)->toBeInstanceOf(ConstantDriver::class)
        ->and($dataset->control->ceiling)->toBe(0.34)
        ->and($dataset->cases)->toHaveCount(3)
        ->and($dataset->cases[0]->sources)->toBe(['notice-101'])
        ->and(array_map(get_class(...), $dataset->cases[0]->graders))->toBe([
            Contains::class, Matches::class, Matches::class, CitationsPresent::class, ReadingLevel::class, Refusal::class,
        ])
        ->and($dataset->cases[1]->graders[0])->toBeInstanceOf(JsonSchema::class);
});

it('loads the same dataset from JSON', function (): void {
    $file = writeDataset(json_encode([
        'name' => 'json-set',
        'control' => ['echo' => true],
        'cases' => [['id' => 'a', 'input' => 'Hi', 'expect' => [['contains' => 'hello']]]],
    ], JSON_THROW_ON_ERROR), 'json');

    $dataset = (new DatasetLoader())->load($file);

    expect($dataset->threshold)->toBe(1.0)
        ->and($dataset->control->ceiling)->toBe(0.0)
        ->and($dataset->control->driver)->toBeInstanceOf(EchoDriver::class);
});

it('resolves a recorded control relative to the dataset', function (): void {
    $file = writeDataset(str_replace('{constant: "nothing", ceiling: 0.0}', '{recorded: recordings/bad.json}', MINIMAL));

    expect((new DatasetLoader())->load($file)->control->driver)->toBeInstanceOf(RecordedDriver::class);
});

it('rejects datasets with problems, naming the location', function (string $yaml, string $message): void {
    expect(fn() => (new DatasetLoader())->load(writeDataset($yaml)))->toThrow(InvalidDataset::class, $message);
})->with([
    'no control' => [str_replace('control: {constant: "nothing", ceiling: 0.0}', '', MINIMAL), 'control is required'],
    'two control kinds' => [str_replace('{constant: "nothing", ceiling: 0.0}', '{constant: "x", echo: true}', MINIMAL), 'control must set exactly one of'],
    'echo false' => [str_replace('{constant: "nothing", ceiling: 0.0}', '{echo: false}', MINIMAL), 'control.echo must be true'],
    'ceiling not below threshold' => [str_replace('ceiling: 0.0', 'ceiling: 0.5', MINIMAL), 'control.ceiling must be below the dataset threshold'],
    'threshold out of range' => [str_replace('threshold: 0.5', 'threshold: 1.5', MINIMAL), 'threshold must be between 0 and 1'],
    'threshold as string' => [str_replace('threshold: 0.5', 'threshold: "high"', MINIMAL), 'threshold must be a number'],
    'unknown top-level key' => [MINIMAL . "\nmodel: claude-opus-5-5", 'model is not a recognised key'],
    'unknown case key' => [str_replace('    input: Say hello.', "    input: Say hello.\n    tags: [a]", MINIMAL), 'cases[0].tags is not a recognised key'],
    'bad name' => [str_replace('name: minimal', 'name: "../etc"', MINIMAL), 'name must be lowercase'],
    'bad case id' => [str_replace('id: one', 'id: "has space"', MINIMAL), 'cases[0].id must be letters'],
    'no cases' => [str_replace(substr(MINIMAL, strpos(MINIMAL, 'cases:')), 'cases: []', MINIMAL), 'cases must contain at least one case'],
    'no graders' => [str_replace("    expect:\n      - contains: hello", '    expect: []', MINIMAL), 'cases[0].expect must contain at least one grader'],
    'unknown grader' => [str_replace('contains: hello', 'sounds_good: true', MINIMAL), 'cases[0].expect[0].sounds_good is not a grader type'],
    'two keys in a grader' => [str_replace('- contains: hello', "- contains: hello\n        regex: /x/", MINIMAL), 'cases[0].expect[0] must have exactly one key'],
    'invalid regex' => [str_replace('contains: hello', 'regex: "/(unclosed/"', MINIMAL), 'cases[0].expect[0].regex is not a valid PCRE pattern'],
    'citations without sources' => [str_replace('contains: hello', 'citations: true', MINIMAL), 'cases[0].sources must list the source ids'],
    'bad reading level' => [str_replace('contains: hello', 'reading_level: {max_grade: 99}', MINIMAL), 'reading_level.max_grade must be between 0 and 30'],
    'json schema as list' => [str_replace('contains: hello', 'json_schema: [1, 2]', MINIMAL), 'json_schema must be a JSON Schema object'],
    'missing input' => [str_replace('    input: Say hello.', '', MINIMAL), 'cases[0].input is required'],
    'empty input' => [str_replace('input: Say hello.', 'input: "  "', MINIMAL), 'cases[0].input must be a non-empty string'],
    'recorded control outside the directory' => [str_replace('{constant: "nothing", ceiling: 0.0}', '{recorded: ../../secrets.json}', MINIMAL), 'control.recorded must be a relative path'],
    'recorded control absolute' => [str_replace('{constant: "nothing", ceiling: 0.0}', '{recorded: /etc/passwd.json}', MINIMAL), 'control.recorded must be a relative path'],
    'top level list' => ['- one', 'must contain a map at the top level'],
    'invalid yaml' => ["name: [unclosed\n", 'is not valid YAML'],
    'php object tag' => [str_replace('input: Say hello.', 'input: !php/object \'O:8:"stdClass":0:{}\'', MINIMAL), 'is not valid YAML'],
]);

it('rejects duplicate case ids', function (): void {
    $yaml = MINIMAL . "\n  - id: one\n    input: Again.\n    expect:\n      - contains: x";

    expect(fn() => (new DatasetLoader())->load(writeDataset($yaml)))->toThrow(InvalidDataset::class, 'cases[1].id "one" is used by more than one case');
});

it('rejects files it cannot or should not read', function (): void {
    $loader = new DatasetLoader();

    expect(fn() => $loader->load('/nonexistent/dataset.yaml'))->toThrow(InvalidDataset::class, 'not found or not readable')
        ->and(fn() => $loader->load(writeDataset(MINIMAL, 'php')))->toThrow(InvalidDataset::class, 'must be .json, .yaml or .yml')
        ->and(fn() => $loader->load(writeDataset('{"name": ', 'json')))->toThrow(InvalidDataset::class, 'is not valid JSON')
        ->and(fn() => $loader->load(writeDataset(str_repeat('#', DatasetLoader::MAX_FILE_BYTES + 1))))->toThrow(InvalidDataset::class, 'is larger than');
});

it('never repeats case input in its error messages', function (): void {
    $yaml = str_replace('input: Say hello.', 'input: "CANARY-INPUT-31f9"', MINIMAL);
    $yaml = str_replace('contains: hello', 'regex: "(bad"', $yaml);

    try {
        (new DatasetLoader())->load(writeDataset($yaml));
    } catch (InvalidDataset $e) {
        expect($e->getMessage())->not->toContain('CANARY-INPUT-31f9');

        return;
    }

    test()->fail('Expected the dataset to be rejected.');
});

it('builds graders registered with extend', function (): void {
    $factory = new GraderFactory();
    $factory->extend('word_count', static fn($entry, string $type) => new Contains((string) $entry->value($type)));

    $dataset = (new DatasetLoader($factory))->load(writeDataset(str_replace('contains: hello', 'word_count: hello', MINIMAL)));

    expect($dataset->cases[0]->graders[0])->toBeInstanceOf(Contains::class)
        ->and(fn() => $factory->extend('Bad Name', static fn() => new Contains('x')))->toThrow(InvalidArgumentException::class);
});
