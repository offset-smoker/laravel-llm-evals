<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use JsonException;
use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\DatasetLoader;
use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;
use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;
use OffsetSmoker\LlmEvals\Drivers\Recording;
use OffsetSmoker\LlmEvals\Reports\ConsoleReport;
use OffsetSmoker\LlmEvals\Reports\JsonReport;
use OffsetSmoker\LlmEvals\Runner\EvalRunner;
use OffsetSmoker\LlmEvals\Runner\RunReport;
use OffsetSmoker\LlmEvals\Runner\Verdict;
use OffsetSmoker\LlmEvals\Support\Text;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Exit codes: 0 every dataset passed; 1 a subject scored below its
 * threshold; 2 a control passed above its ceiling; 3 invalid input or
 * configuration. With several datasets the highest code wins.
 */
final class RunEvalsCommand extends Command
{
    protected $signature = 'evals:run
        {datasets?* : Dataset names or paths inside the datasets directory; all datasets in it when omitted}
        {--driver= : The driver to evaluate (defaults to llm-evals.default_driver)}
        {--record : Save the subject\'s responses as each dataset\'s recording}
        {--json= : Also write a JSON report to this .json file}
        {--with-outputs : Include model outputs in the console and JSON reports}';

    protected $description = 'Run LLM evaluation datasets and their falsifiability controls';

    private const array EXTENSIONS = ['json', 'yaml', 'yml'];

    public function handle(Repository $config, DatasetLoader $loader, DriverRegistry $drivers, EvalRunner $runner): int
    {
        try {
            $datasets = array_map($loader->load(...), $this->datasetFiles($config));
            $driverName = $this->driverName($config, $drivers);
            $subject = $drivers->make($driverName);
            $jsonFile = $this->jsonFile();
        } catch (InvalidDataset|InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        }

        $reports = [];

        foreach ($datasets as $dataset) {
            try {
                $report = $runner->run($dataset, $subject);
                ConsoleReport::render($report, $this->output, (bool) $this->option('with-outputs'));
                $reports[] = $report;

                if ($this->option('record') === true && $report->subject !== null) {
                    $this->record($config, $dataset, $report);
                }
            } catch (RuntimeException $e) {
                // A missing recording, an unwritable recordings directory: a setup problem, never a score.
                return $this->invalid($e->getMessage());
            }
        }

        $exitCode = Verdict::Passed->value;

        foreach ($reports as $report) {
            $exitCode = max($exitCode, $report->verdict()->value);
        }

        if ($jsonFile !== null) {
            try {
                $this->writeJson($jsonFile, $reports, $exitCode);
            } catch (RuntimeException $e) {
                return $this->invalid($e->getMessage());
            }
        }

        $this->newLine();
        $this->line(count($reports) . ' dataset' . (count($reports) === 1 ? '' : 's') . ' run, exit code ' . $exitCode . '.');

        return $exitCode;
    }

    /**
     * @return list<string>
     */
    private function datasetFiles(Repository $config): array
    {
        $configured = $config->get('llm-evals.datasets_path');
        $base = is_string($configured) ? realpath($configured) : false;

        if ($base === false || ! is_dir($base)) {
            throw new InvalidArgumentException('The datasets directory (config llm-evals.datasets_path) does not exist.');
        }

        /** @var list<string> $arguments */
        $arguments = (array) $this->argument('datasets');

        if ($arguments === []) {
            $files = [];
            // scandir rather than glob: a directory name with glob metacharacters ("[1]") must still work.
            $entries = scandir($base);

            foreach ($entries === false ? [] : $entries as $entry) {
                if (! in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    continue;
                }

                $real = realpath($base . DIRECTORY_SEPARATOR . $entry);

                if ($real === false || ! is_file($real)) {
                    continue;
                }

                if (! str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                    throw new InvalidArgumentException("Dataset \"{$entry}\" is a link to a file outside the datasets directory {$base}.");
                }

                $files[] = $real;
            }

            if ($files === []) {
                throw new InvalidArgumentException("No datasets found in {$base}. An empty run is not a pass.");
            }

            return $files;
        }

        return array_values(array_unique(array_map(fn(string $argument): string => $this->resolveInside($base, $argument), $arguments)));
    }

    private function resolveInside(string $base, string $argument): string
    {
        $candidate = str_starts_with($argument, DIRECTORY_SEPARATOR) ? $argument : $base . DIRECTORY_SEPARATOR . $argument;
        $options = in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            ? [$candidate]
            : ["{$candidate}.yaml", "{$candidate}.yml", "{$candidate}.json"];

        foreach ($options as $option) {
            $real = realpath($option);

            if ($real === false || ! is_file($real)) {
                continue;
            }

            if (! str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException("Dataset \"{$argument}\" is outside the datasets directory {$base}.");
            }

            return $real;
        }

        throw new InvalidArgumentException("Dataset \"{$argument}\" was not found in {$base}.");
    }

    private function driverName(Repository $config, DriverRegistry $drivers): string
    {
        $option = $this->option('driver');
        $name = is_string($option) && $option !== '' ? $option : $config->get('llm-evals.default_driver');

        if (! is_string($name) || ! $drivers->has($name)) {
            $shown = is_string($name) ? $name : get_debug_type($name);

            throw new InvalidArgumentException("Unknown driver \"{$shown}\" (registered: " . implode(', ', $drivers->names()) . ').');
        }

        if ($name === 'recorded' && $this->option('record') === true) {
            throw new InvalidArgumentException('--record cannot be used with the recorded driver; it would overwrite a recording with itself.');
        }

        return $name;
    }

    private function jsonFile(): ?string
    {
        $option = $this->option('json');

        if ($option === null || $option === '') {
            return null;
        }

        if (! is_string($option) || ! str_ends_with(strtolower($option), '.json')) {
            throw new InvalidArgumentException('--json must name a .json file.');
        }

        if (! is_dir(dirname($option))) {
            throw new InvalidArgumentException('The directory for --json (' . dirname($option) . ') does not exist.');
        }

        return $option;
    }

    private function record(Repository $config, Dataset $dataset, RunReport $report): void
    {
        $directory = $config->get('llm-evals.recordings_path');

        if (! is_string($directory) || $directory === '' || $report->subject === null) {
            $this->warn('Not recording: config llm-evals.recordings_path is not set.');

            return;
        }

        $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $dataset->name . '.json';
        $fresh = $report->subject->responses();

        // Cases that errored this time keep their earlier response, so an outage never erases a good recording.
        $kept = is_file($file) ? Recording::read($file, $dataset->name) : [];
        $responses = [];

        foreach ($dataset->cases as $case) {
            $response = $fresh[$case->id] ?? $kept[$case->id] ?? null;

            if ($response !== null) {
                $responses[$case->id] = $response;
            }
        }

        Recording::write($file, $dataset->name, $responses);

        $errored = $report->subject->erroredCount();
        $carried = count($responses) - count($fresh);
        $note = $errored === 0 ? '' : " ({$errored} errored cases not re-recorded; {$carried} kept from the previous recording)";
        $this->line("  recorded to {$file}{$note}");
    }

    /**
     * @param  list<RunReport>  $reports
     */
    private function writeJson(string $file, array $reports, int $exitCode): void
    {
        $withOutputs = (bool) $this->option('with-outputs');

        try {
            $json = json_encode([
                'exit_code' => $exitCode,
                'runs' => array_map(static fn(RunReport $report): array => JsonReport::toArray($report, $withOutputs), $reports),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode the JSON report: {$e->getMessage()}");
        }

        set_error_handler(static fn(): bool => true);

        try {
            $written = file_put_contents($file, $json . "\n");
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            throw new RuntimeException("Could not write the JSON report to {$file}.");
        }
    }

    private function invalid(string $message): int
    {
        $this->error(OutputFormatter::escape(Text::terminalSafe($message)));

        return Verdict::InvalidInput->value;
    }
}
