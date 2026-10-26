<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Dataset\Control;
use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\ConstantDriver;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Graders\Grader;
use OffsetSmoker\LlmEvals\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function fixturePath(string $path): string
{
    return __DIR__ . '/Fixtures/' . $path;
}

function examplesPath(string $path = ''): string
{
    return dirname(__DIR__) . '/examples/evals' . ($path === '' ? '' : '/' . $path);
}

/**
 * @param  list<string>  $sources
 */
function evalCase(string $input = 'input', array $sources = [], Grader ...$graders): EvalCase
{
    return new EvalCase('case-1', $input, $sources, array_values($graders));
}

function reply(string $text, ?string $stopReason = null): Response
{
    return new Response($text, $stopReason);
}

/**
 * @param  list<EvalCase>  $cases
 */
function makeDataset(array $cases, float $threshold = 1.0, float $ceiling = 0.0, string $control = 'nope', ?string $system = null): Dataset
{
    return new Dataset('test-set', $threshold, $system, new Control(new ConstantDriver($control), $ceiling), $cases, '/dev/null');
}

/**
 * A scratch directory removed after the test.
 */
function scratchDirectory(): string
{
    $directory = sys_get_temp_dir() . '/llm-evals-' . bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    register_shutdown_function(static function () use ($directory): void {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    });

    return $directory;
}
