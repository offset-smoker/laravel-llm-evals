<?php

declare(strict_types=1);

/*
 * Boots a bare Laravel application whose AppServiceProvider::boot() runs the
 * PHP code in the file named by $argv[1], with $argv[3] as its base path,
 * then prints what happened as JSON.
 *
 * With "no-dev" as $argv[2], the package's classes are made unloadable first,
 * which is what an application sees after `composer install --no-dev` when
 * the package is a dev dependency: its provider is not discovered and none of
 * its classes exist.
 */

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\LlmEvalsServiceProvider;

$loader = require __DIR__ . '/../../../vendor/autoload.php';
[, $snippetFile, $mode, $basePath] = $argv;
$installed = $mode === 'installed';

if (! $installed) {
    $loader->setPsr4('OffsetSmoker\\LlmEvals\\', []);
}

final class AppServiceProvider extends ServiceProvider
{
    public static string $snippet = '';

    public function boot(): void
    {
        eval(self::$snippet);
    }
}

AppServiceProvider::$snippet = preg_replace('/^<\?php\s*/', '', (string) file_get_contents($snippetFile)) ?? '';

try {
    $app = new Application($basePath);
    $app->instance('config', new Repository([]));

    if ($installed) {
        $app->register(LlmEvalsServiceProvider::class);
    }

    $app->register(AppServiceProvider::class);
    $app->boot();

    $result = ['booted' => true];

    if ($installed) {
        $result['drivers'] = $app->make(DriverRegistry::class)->names();
        $result['graders'] = $app->make(GraderFactory::class)->types();
    }
} catch (Throwable $e) {
    $result = ['booted' => false, 'error' => $e::class . ': ' . $e->getMessage()];
}

echo json_encode($result, JSON_THROW_ON_ERROR);
