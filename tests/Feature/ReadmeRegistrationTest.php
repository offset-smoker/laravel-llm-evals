<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The README's examples that register a driver or a grader in a service
 * provider. The package is installed with --dev, so these run in production
 * too, where the package is absent.
 *
 * @return list<string>
 */
function readmeRegistrationExamples(): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__ . '/../../README.md'), $blocks);

    return array_values(array_filter($blocks[1], static fn(string $block): bool => str_contains($block, '->extend(')));
}

/**
 * Boots a bare Laravel application in a separate PHP process whose
 * AppServiceProvider::boot() runs $code.
 *
 * @return array{booted: bool, error?: string, drivers?: list<string>, graders?: list<string>}
 */
function bootApplicationWith(string $code, string $mode): array
{
    $basePath = scratchDirectory();
    $file = "{$basePath}/snippet.php";
    file_put_contents($file, "<?php\n" . $code);

    $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/readme/boot-app.php', $file, $mode, $basePath]);
    $process->mustRun();

    /** @var array{booted: bool, error?: string, drivers?: list<string>, graders?: list<string>} */
    return json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
}

it('finds the README registration examples', function (): void {
    expect(readmeRegistrationExamples())->toHaveCount(2);
});

it('boots a production install, without the dev package, with each README registration example', function (): void {
    foreach (readmeRegistrationExamples() as $example) {
        expect(bootApplicationWith($example, 'no-dev'))->toBe(['booted' => true]);
    }
});

it('registers the README driver and grader where the package is installed', function (): void {
    $result = bootApplicationWith(implode("\n", readmeRegistrationExamples()), 'installed');

    expect($result['booted'])->toBeTrue()
        ->and($result['drivers'] ?? [])->toContain('notice-rewriter')
        ->and($result['graders'] ?? [])->toContain('mentions_township');
});

it('fails to boot without the package when an example resolves the registry eagerly (control)', function (): void {
    $eager = <<<'PHP'
        use OffsetSmoker\LlmEvals\Drivers\CallbackDriver;
        use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;

        $this->app->make(DriverRegistry::class)->extend('notice-rewriter', fn () => new CallbackDriver(fn () => '', 'notice-rewriter'));
        PHP;

    $result = bootApplicationWith($eager, 'no-dev');

    expect($result['booted'])->toBeFalse()
        ->and($result['error'] ?? '')->toContain('DriverRegistry');
});
