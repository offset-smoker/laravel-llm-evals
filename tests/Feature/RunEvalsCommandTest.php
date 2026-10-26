<?php

declare(strict_types=1);

use Anthropic\Client;
use Illuminate\Support\Facades\Artisan;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\CallbackDriver;
use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;
use OffsetSmoker\LlmEvals\Drivers\Recording;
use OffsetSmoker\LlmEvals\Tests\Support\FakeTransport;

/**
 * Copies the shipped example into a scratch datasets directory and points
 * the package config at it.
 */
function useExampleDatasets(): string
{
    $directory = scratchDirectory();
    mkdir("{$directory}/recordings");
    copy(examplesPath('plain-language-notices.yaml'), "{$directory}/plain-language-notices.yaml");
    copy(examplesPath('recordings/plain-language-notices.json'), "{$directory}/recordings/plain-language-notices.json");

    config()->set('llm-evals.datasets_path', $directory);
    config()->set('llm-evals.recordings_path', "{$directory}/recordings");

    return $directory;
}

function runEvals(array $arguments = []): array
{
    $exitCode = Artisan::call('evals:run', $arguments);

    return [$exitCode, Artisan::output()];
}

function registerSubject(Closure $callback): void
{
    app(DriverRegistry::class)->extend('subject', static fn() => new CallbackDriver($callback, 'subject'));
}

it('passes the shipped example with the default recorded driver', function (): void {
    useExampleDatasets();

    [$exitCode, $output] = runEvals();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('plain-language-notices')
        ->and($output)->toContain('control (constant): 1/3 passed')
        ->and($output)->toContain('held')
        ->and($output)->toContain('3/3 passed (100%)')
        ->and($output)->toContain('PASSED (exit 0)')
        ->and($output)->toContain('1 dataset run, exit code 0.');
});

it('exits 1 when the subject falls below the threshold and names the failing graders', function (): void {
    useExampleDatasets();
    registerSubject(static fn(EvalCase $case): string => 'The notice is about something.');

    [$exitCode, $output] = runEvals(['--driver' => 'subject']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('✗ water-main-closure')
        ->and($output)->toContain('contains "Elm Street": not found')
        ->and($output)->toContain('BELOW THRESHOLD (exit 1)');
});

it('exits 2 and skips the subject when the control is too strong', function (): void {
    $directory = useExampleDatasets();
    $yaml = (string) file_get_contents("{$directory}/plain-language-notices.yaml");
    file_put_contents("{$directory}/plain-language-notices.yaml", str_replace('ceiling: 0.34', 'ceiling: 0.0', $yaml));

    $called = false;
    registerSubject(static function () use (&$called): string {
        $called = true;

        return '';
    });

    [$exitCode, $output] = runEvals(['--driver' => 'subject']);

    expect($exitCode)->toBe(2)
        ->and($called)->toBeFalse()
        ->and($output)->toContain('TOO STRONG')
        ->and($output)->toContain('The subject was not run.')
        ->and($output)->toContain('✓ refuses-resident-address')
        ->and($output)->toContain('refuses: refused')
        ->and($output)->toContain('CONTROL TOO STRONG (exit 2)');
});

it('exits 3 on invalid input', function (array $arguments, ?Closure $setup, string $message): void {
    useExampleDatasets();

    if ($setup !== null) {
        $setup();
    }

    [$exitCode, $output] = runEvals($arguments);

    expect($exitCode)->toBe(3)->and($output)->toContain($message);
})->with([
    'unknown driver' => [['--driver' => 'nope'], null, 'Unknown driver "nope" (registered: recorded, claude)'],
    'unknown dataset' => [['datasets' => ['missing']], null, 'Dataset "missing" was not found'],
    'dataset name read as an extension' => [['datasets' => ['plain-language-notices.v2']], null, 'Dataset "plain-language-notices.v2" was not found'],
    'record with recorded' => [['--record' => true], null, '--record cannot be used with the recorded driver'],
    'json without extension' => [['--json' => '/tmp/report.txt'], null, '--json must name a .json file'],
    'json in a missing directory' => [['--json' => '/nonexistent/dir/report.json'], null, 'does not exist'],
    'missing datasets directory' => [[], static fn() => config()->set('llm-evals.datasets_path', '/nonexistent/evals'), 'The datasets directory'],
    'missing recording' => [[], static fn() => config()->set('llm-evals.recordings_path', '/nonexistent/recordings'), 'recording not found'],
    'claude without a key' => [['--driver' => 'claude'], static fn() => config()->set('llm-evals.claude.api_key', null), 'needs an API key'],
    'claude with a bad model' => [['--driver' => 'claude'], static function (): void {
        config()->set('llm-evals.claude.api_key', 'sk-ant-test');
        config()->set('llm-evals.claude.model', 'gpt-4');
    }, 'model id must look like'],
]);

it('rejects a dataset path that escapes the datasets directory through a symlink', function (): void {
    $directory = useExampleDatasets();
    $outside = scratchDirectory();
    copy(examplesPath('plain-language-notices.yaml'), "{$outside}/elsewhere.yaml");
    symlink("{$outside}/elsewhere.yaml", "{$directory}/linked.yaml");

    [$exitCode, $output] = runEvals(['datasets' => ['linked']]);

    expect($exitCode)->toBe(3)->and($output)->toContain('is outside the datasets directory');
});

it('treats an empty datasets directory as a failure, not a pass', function (): void {
    config()->set('llm-evals.datasets_path', scratchDirectory());

    [$exitCode, $output] = runEvals();

    expect($exitCode)->toBe(3)->and($output)->toContain('An empty run is not a pass');
});

it('validates every dataset before running any', function (): void {
    $directory = useExampleDatasets();
    file_put_contents("{$directory}/broken.yaml", "name: broken\ncases: []\n");

    $called = false;
    registerSubject(static function () use (&$called): string {
        $called = true;

        return '';
    });

    [$exitCode, $output] = runEvals(['--driver' => 'subject']);

    expect($exitCode)->toBe(3)->and($called)->toBeFalse()->and($output)->toContain('broken.yaml: control is required');
});

it('returns the highest exit code across datasets', function (): void {
    $directory = useExampleDatasets();
    $yaml = (string) file_get_contents("{$directory}/plain-language-notices.yaml");
    file_put_contents("{$directory}/strict-control.yaml", str_replace(['name: plain-language-notices', 'ceiling: 0.34'], ['name: strict-control', 'ceiling: 0.0'], $yaml));
    copy("{$directory}/recordings/plain-language-notices.json", "{$directory}/recordings/strict-control.json");

    [$exitCode, $output] = runEvals();

    expect($exitCode)->toBe(2)->and($output)->toContain('2 datasets run, exit code 2.');
});

it('runs only the named datasets, with or without an extension', function (): void {
    $directory = useExampleDatasets();
    file_put_contents("{$directory}/broken.yaml", 'not: [valid');

    expect(runEvals(['datasets' => ['plain-language-notices']])[0])->toBe(0)
        ->and(runEvals(['datasets' => ['plain-language-notices.yaml']])[0])->toBe(0);
});

it('writes a JSON report without inputs, system prompt or outputs by default', function (): void {
    $directory = useExampleDatasets();
    $file = "{$directory}/report.json";

    [$exitCode] = runEvals(['--json' => $file]);
    $json = (string) file_get_contents($file);
    $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['exit_code'])->toBe(0)
        ->and($report['runs'][0]['dataset'])->toBe('plain-language-notices')
        ->and($report['runs'][0]['verdict'])->toBe('passed')
        ->and($report['runs'][0]['control']['held'])->toBeTrue()
        ->and($report['runs'][0]['subject']['pass_rate'])->toBe(1)
        ->and($report['runs'][0]['subject']['results'][0]['graders'][0])->toBe(['grader' => 'contains "Elm Street"', 'passed' => true, 'reason' => 'found'])
        ->and($json)->not->toContain('Rewrite notice-101')
        ->and($json)->not->toContain('You rewrite public notices')
        ->and($json)->not->toContain('The water main under Elm Street');
});

it('includes outputs only with --with-outputs, stripped of terminal escapes', function (): void {
    useExampleDatasets();
    registerSubject(static fn(EvalCase $case): string => "Elm Street \e[2J\e]0;pwned\x07 Tuesday 9 a.m. <error>tag</error>");

    [, $output] = runEvals(['--driver' => 'subject', '--with-outputs' => true]);

    expect($output)->toContain('│ Elm Street [2J]0;pwned Tuesday 9 a.m. <error>tag</error>')
        ->and($output)->not->toContain("\e");

    [, $without] = runEvals(['--driver' => 'subject']);

    expect($without)->not->toContain('Tuesday 9 a.m.');
});

it('never prints the case input or system prompt', function (): void {
    $directory = useExampleDatasets();
    $yaml = (string) file_get_contents("{$directory}/plain-language-notices.yaml");
    file_put_contents("{$directory}/plain-language-notices.yaml", str_replace(['Rewrite notice-101', 'You rewrite public notices'], ['CANARY-INPUT-5b1e', 'CANARY-SYSTEM-0c4d'], $yaml));
    registerSubject(static fn(): string => 'irrelevant');

    [, $output] = runEvals(['--driver' => 'subject']);

    expect($output)->not->toContain('CANARY-INPUT-5b1e')->and($output)->not->toContain('CANARY-SYSTEM-0c4d');
});

it('records a live run and replays it offline', function (): void {
    $directory = useExampleDatasets();
    unlink("{$directory}/recordings/plain-language-notices.json");

    $transport = (new FakeTransport())
        ->replay('message-water-main.json')
        ->replay('message-json.json')
        ->replay('message-address-refusal.json');

    app()->instance(Client::class, new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]));

    [$exitCode, $output] = runEvals(['--driver' => 'claude', '--record' => true]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('subject (claude:claude-opus-5-5)')
        ->and($output)->toContain('tokens: 992 in, 123 out, 4096 cache read, 2048 cache write')
        ->and($output)->toContain("recorded to {$directory}/recordings/plain-language-notices.json")
        ->and($transport->requests)->toHaveCount(3)
        ->and($transport->body(0)['system'])->toBe($transport->body(2)['system'])
        ->and($transport->body(0)['system'][0]['cache_control'])->toBe(['type' => 'ephemeral']);

    $recording = Recording::read("{$directory}/recordings/plain-language-notices.json", 'plain-language-notices');

    expect(array_keys($recording))->toBe(['water-main-closure', 'permit-hearing-json', 'refuses-resident-address'])
        ->and(runEvals()[0])->toBe(0);
});

it('counts a failed API call as a failed case and keeps the earlier recording for it', function (): void {
    $directory = useExampleDatasets();

    $transport = (new FakeTransport())
        ->replay('message-water-main.json')
        ->replay('error-overloaded.json', 529)
        ->replay('message-address-refusal.json');

    app()->instance(Client::class, new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]));

    [$exitCode, $output] = runEvals(['--driver' => 'claude', '--record' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('✗ permit-hearing-json  Claude returned HTTP 529 overloaded_error')
        ->and($output)->toContain('(1 errored cases not re-recorded; 1 kept from the previous recording)');

    $recording = Recording::read("{$directory}/recordings/plain-language-notices.json", 'plain-language-notices');

    expect(array_keys($recording))->toBe(['water-main-closure', 'permit-hearing-json', 'refuses-resident-address'])
        ->and($recording['water-main-closure']->text)->toStartWith('The water main under Elm Street will be shut off on Tuesday at 9:00 a.m. It should')
        ->and($recording['permit-hearing-json']->text)->toContain('ZP-2026-031');
});

it('builds the claude driver from config', function (): void {
    config()->set('llm-evals.claude.api_key', 'sk-ant-test');
    config()->set('llm-evals.claude.model', 'claude-sonnet-5-5');

    expect(app(DriverRegistry::class)->make('claude')->name())->toBe('claude:claude-sonnet-5-5');
});

it('merges the package config and publishes it', function (): void {
    expect(config('llm-evals.default_driver'))->toBe('recorded')
        ->and(config('llm-evals.claude.model'))->toBe('claude-opus-5-5')
        ->and(config('llm-evals.claude.effort'))->toBe('medium');

    $this->artisan('vendor:publish', ['--tag' => 'llm-evals-config'])->assertSuccessful();

    expect(config_path('llm-evals.php'))->toBeFile();
    unlink(config_path('llm-evals.php'));
});

it('exits 3 when the JSON report cannot be written', function (): void {
    $directory = useExampleDatasets();
    mkdir("{$directory}/report.json");

    // The directory named like a dataset is skipped, and cannot be written as a report.
    [$exitCode, $output] = runEvals(['--json' => "{$directory}/report.json"]);

    expect($exitCode)->toBe(3)->and($output)->toContain('Could not write the JSON report');
});

it('rejects a dataset reached through ../ that resolves outside the datasets directory', function (): void {
    $directory = useExampleDatasets();
    $outside = scratchDirectory();
    copy(examplesPath('plain-language-notices.yaml'), "{$outside}/elsewhere.yaml");
    $relative = '../' . basename($outside) . '/elsewhere.yaml';

    expect(is_file("{$directory}/{$relative}"))->toBeTrue();

    [$exitCode, $output] = runEvals(['datasets' => [$relative]]);

    expect($exitCode)->toBe(3)->and($output)->toContain('is outside the datasets directory');
});

it('rejects a symlink that escapes the datasets directory when running everything', function (): void {
    $directory = useExampleDatasets();
    $outside = scratchDirectory();
    copy(examplesPath('plain-language-notices.yaml'), "{$outside}/elsewhere.yaml");
    symlink("{$outside}/elsewhere.yaml", "{$directory}/linked.yaml");

    [$exitCode, $output] = runEvals();

    expect($exitCode)->toBe(3)->and($output)->toContain('Dataset "linked.yaml" is a link to a file outside the datasets directory');
});

it('finds datasets in a directory whose name has glob characters', function (): void {
    $directory = scratchDirectory() . '/evals [1]';
    mkdir("{$directory}/recordings", 0o755, true);
    copy(examplesPath('plain-language-notices.yaml'), "{$directory}/plain-language-notices.yaml");
    copy(examplesPath('recordings/plain-language-notices.json'), "{$directory}/recordings/plain-language-notices.json");
    config()->set('llm-evals.datasets_path', $directory);
    config()->set('llm-evals.recordings_path', "{$directory}/recordings");

    expect(runEvals()[0])->toBe(0);
});

it('turns any exception from the subject into a failed case without its message', function (): void {
    useExampleDatasets();
    registerSubject(static function (EvalCase $case): string {
        throw new LogicException("leaking {$case->input}");
    });

    [$exitCode, $output] = runEvals(['--driver' => 'subject']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('The driver threw LogicException at RunEvalsCommandTest.php:')
        ->and($output)->not->toContain('leaking')
        ->and($output)->toContain('1 dataset run, exit code 1.');
});

it('checks the existing recording before a --record run calls the subject', function (): void {
    $directory = useExampleDatasets();
    $file = "{$directory}/recordings/plain-language-notices.json";
    file_put_contents($file, str_replace('"dataset": "plain-language-notices"', '"dataset": "renamed"', (string) file_get_contents($file)));
    $before = (string) file_get_contents($file);

    $called = false;
    registerSubject(static function () use (&$called): string {
        $called = true;

        return 'Elm Street';
    });

    [$exitCode, $output] = runEvals(['--driver' => 'subject', '--record' => true]);

    expect($exitCode)->toBe(3)
        ->and($called)->toBeFalse()
        ->and($output)->toContain('dataset must be "plain-language-notices"')
        ->and((string) file_get_contents($file))->toBe($before);
});

it('checks that --record can write before it calls the subject', function (): void {
    useExampleDatasets();
    config()->set('llm-evals.recordings_path', '/dev/null/recordings');

    $called = false;
    registerSubject(static function () use (&$called): string {
        $called = true;

        return 'Elm Street';
    });

    [$exitCode, $output] = runEvals(['--driver' => 'subject', '--record' => true]);

    expect($exitCode)->toBe(3)->and($called)->toBeFalse()->and($output)->toContain('Could not create the recordings directory');
});

it('rejects two dataset files with the same name, which would share one recording', function (): void {
    $directory = useExampleDatasets();
    copy("{$directory}/plain-language-notices.yaml", "{$directory}/copy-of-notices.yaml");

    [$exitCode, $output] = runEvals();

    expect($exitCode)->toBe(3)
        ->and($output)->toContain('Dataset name "plain-language-notices" is used by both')
        ->and($output)->toContain('copy-of-notices.yaml');
});

it('keeps the other datasets and the JSON report when one dataset stops with an error', function (): void {
    $directory = useExampleDatasets();
    $yaml = (string) file_get_contents("{$directory}/plain-language-notices.yaml");
    // Sorted first, and has no recording, so the recorded driver cannot run it.
    file_put_contents("{$directory}/a-unrecorded.yaml", str_replace('name: plain-language-notices', 'name: a-unrecorded', $yaml));

    [$exitCode, $output] = runEvals(['--json' => "{$directory}/report.json"]);
    $report = json_decode((string) file_get_contents("{$directory}/report.json"), true, 64, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(3)
        ->and($output)->toContain('recording not found')
        ->and($output)->toContain('✓ water-main-closure')
        ->and($output)->toContain('1 dataset run, 1 stopped by an error, exit code 3.')
        ->and($report['exit_code'])->toBe(3)
        ->and(array_column($report['runs'], 'dataset'))->toBe(['plain-language-notices'])
        ->and($report['runs'][0]['exit_code'])->toBe(0)
        ->and($report['errors'])->toHaveCount(1)
        ->and($report['errors'][0]['dataset'])->toBe('a-unrecorded')
        ->and($report['errors'][0]['error'])->toContain('recording not found');
});

it('exits 3 and still reports the run when the recording cannot be written after it', function (): void {
    $directory = useExampleDatasets();
    unlink("{$directory}/recordings/plain-language-notices.json");
    mkdir("{$directory}/recordings/plain-language-notices.json");
    registerSubject(static fn(): string => 'Elm Street');

    [$exitCode, $output] = runEvals(['--driver' => 'subject', '--record' => true]);

    expect($exitCode)->toBe(3)
        ->and($output)->toContain('subject (subject):')
        ->and($output)->toContain('Could not write the recording')
        ->and($output)->toContain('1 dataset run, 1 stopped by an error, exit code 3.');
});
