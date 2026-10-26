<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

use JsonException;
use OffsetSmoker\LlmEvals\Drivers\ConstantDriver;
use OffsetSmoker\LlmEvals\Drivers\Driver;
use OffsetSmoker\LlmEvals\Drivers\EchoDriver;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Graders\CitationsPresent;
use OffsetSmoker\LlmEvals\Graders\Grader;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\Support\Spec;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads a dataset from a `.json`, `.yaml` or `.yml` file and validates all
 * of it before anything runs: unknown keys, wrong types, duplicate case ids,
 * invalid patterns and schemas, and a missing control are all errors.
 */
final readonly class DatasetLoader
{
    public const int MAX_FILE_BYTES = 5_000_000;

    public const int MAX_CASES = 5_000;

    public const int MAX_INPUT_LENGTH = 200_000;

    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    public const string CASE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    public function __construct(private GraderFactory $graders = new GraderFactory()) {}

    public function load(string $file): Dataset
    {
        $root = Spec::root($this->decode($file), $file);
        $root->onlyKeys('name', 'description', 'threshold', 'system', 'control', 'cases');

        $name = $root->string('name', 100);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $root->fail('name', 'must be lowercase letters, digits, ".", "_" or "-", starting with a letter or digit.');
        }

        $root->optionalString('description', 2_000);
        $threshold = $root->optionalFloat('threshold', 0, 1) ?? 1.0;

        if (! $root->has('control')) {
            $root->fail('control', 'is required: every dataset needs a deliberately bad baseline that must fail its graders (see the README, "The falsifiability control").');
        }

        $control = $this->control($root->map('control'), $file, $threshold);

        $cases = [];
        $seen = [];

        foreach ($root->listOfMaps('cases', self::MAX_CASES) as $spec) {
            $case = $this->case($spec);

            if (isset($seen[$case->id])) {
                $spec->fail('id', "\"{$case->id}\" is used by more than one case.");
            }

            $seen[$case->id] = true;
            $cases[] = $case;
        }

        if ($cases === []) {
            $root->fail('cases', 'must contain at least one case.');
        }

        return new Dataset($name, $threshold, $root->optionalString('system', self::MAX_INPUT_LENGTH), $control, $cases, $file);
    }

    /**
     * Builds graders from a list of entries, as in a case's `expect` list.
     *
     * @param  list<mixed>  $entries
     * @return list<Grader>
     */
    public function graders(array $entries, string $label = 'expect'): array
    {
        $spec = Spec::root(['expect' => $entries], $label);

        return array_map($this->graders->make(...), $spec->listOfMaps('expect', 100));
    }

    private function decode(string $file): mixed
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw InvalidDataset::file($file, 'datasets must be .json, .yaml or .yml files.');
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw InvalidDataset::file($file, 'not found or not readable.');
        }

        $size = filesize($file);

        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw InvalidDataset::file($file, 'is larger than ' . self::MAX_FILE_BYTES . ' bytes.');
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw InvalidDataset::file($file, 'could not be read.');
        }

        if ($extension === 'json') {
            try {
                return json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw InvalidDataset::file($file, "is not valid JSON ({$e->getMessage()}).");
            }
        }

        try {
            // No PHP objects, constants or custom tags; a !php/object tag is an error, not a silent null.
            return Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw InvalidDataset::file($file, "is not valid YAML ({$e->getMessage()}).");
        }
    }

    private function control(Spec $spec, string $file, float $threshold): Control
    {
        $spec->onlyKeys('constant', 'echo', 'recorded', 'ceiling');

        $kinds = array_values(array_intersect($spec->keys(), ['constant', 'echo', 'recorded']));

        if (count($kinds) !== 1) {
            $spec->failHere('must set exactly one of "constant", "echo" or "recorded".');
        }

        $ceiling = $spec->optionalFloat('ceiling', 0, 1) ?? 0.0;

        if ($ceiling >= $threshold) {
            $spec->fail('ceiling', "must be below the dataset threshold ({$threshold}), or a run could pass with graders that cannot tell good output from bad.");
        }

        $driver = match ($kinds[0]) {
            'constant' => new ConstantDriver($spec->string('constant', 10_000)),
            'echo' => $spec->optionalBool('echo') === true ? new EchoDriver() : $spec->fail('echo', 'must be true.'),
            default => $this->recordedControl($spec, $file),
        };

        return new Control($driver, $ceiling);
    }

    private function recordedControl(Spec $spec, string $file): Driver
    {
        $path = $spec->string('recorded', 500);
        $segments = preg_split('#[/\\\\]#', $path);
        $segments = $segments === false ? [] : $segments;

        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path) === 1
            || in_array('..', $segments, true) || ! str_ends_with(strtolower($path), '.json')) {
            $spec->fail('recorded', 'must be a relative path to a .json recording inside the dataset\'s directory.');
        }

        return RecordedDriver::fromFile(dirname($file) . DIRECTORY_SEPARATOR . $path);
    }

    private function case(Spec $spec): EvalCase
    {
        $spec->onlyKeys('id', 'input', 'sources', 'expect');

        $id = $spec->string('id', 128);

        if (preg_match(self::CASE_ID_PATTERN, $id) !== 1) {
            $spec->fail('id', 'must be letters, digits, ".", "_", ":" or "-", starting with a letter or digit.');
        }

        $sources = $spec->listOfStrings('sources', 1_000, 128);
        $graders = array_map($this->graders->make(...), $spec->listOfMaps('expect', 100));

        if ($graders === []) {
            $spec->fail('expect', 'must contain at least one grader; a case with no expectations always passes.');
        }

        foreach ($graders as $grader) {
            if ($grader instanceof CitationsPresent && $sources === []) {
                $spec->fail('sources', 'must list the source ids this case may cite, because it uses the citations grader.');
            }
        }

        return new EvalCase($id, $spec->string('input', self::MAX_INPUT_LENGTH), $sources, $graders);
    }
}
