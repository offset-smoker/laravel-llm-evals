<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Every `uses:` line in the repository's workflows, as written.
 *
 * @return list<string>
 */
function workflowActionLines(): array
{
    $lines = [];

    foreach (glob(__DIR__ . '/../../.github/workflows/*.yml') ?: [] as $workflow) {
        foreach (file($workflow, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:-\s+)?uses:\s*/', $line) === 1) {
                $lines[] = trim($line);
            }
        }
    }

    return $lines;
}

it('pins every workflow action to a full commit SHA with a version comment', function (): void {
    $lines = workflowActionLines();

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line)->toMatch('/^(?:-\s+)?uses:\s+[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}\s+#\s+v?\d+\.\d+\.\d+$/');
    }
});

it('has Dependabot keep the GitHub Actions and Composer dependencies current', function (): void {
    $file = __DIR__ . '/../../.github/dependabot.yml';

    expect($file)->toBeFile();

    /** @var array{version: int, updates: list<array{package-ecosystem: string, directory: string}>} $config */
    $config = Yaml::parseFile($file);

    expect($config['version'])->toBe(2)
        ->and(array_column($config['updates'], 'package-ecosystem'))->toEqualCanonicalizing(['github-actions', 'composer']);
});
