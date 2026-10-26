<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals;

use Anthropic\Client;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OffsetSmoker\LlmEvals\Console\RunEvalsCommand;
use OffsetSmoker\LlmEvals\Dataset\DatasetLoader;
use OffsetSmoker\LlmEvals\Drivers\ClaudeDriver;
use OffsetSmoker\LlmEvals\Drivers\Driver;
use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\Runner\EvalRunner;

final class LlmEvalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/llm-evals.php', 'llm-evals');

        $this->app->singleton(GraderFactory::class);
        $this->app->singleton(DatasetLoader::class, static fn(Application $app): DatasetLoader => new DatasetLoader($app->make(GraderFactory::class)));
        $this->app->singleton(EvalRunner::class);

        $this->app->singleton(DriverRegistry::class, static function (Application $app): DriverRegistry {
            $registry = new DriverRegistry();
            $config = $app->make(Repository::class);

            $registry->extend('recorded', static fn(): Driver => RecordedDriver::fromDirectory(self::string($config, 'llm-evals.recordings_path')));
            $registry->extend('claude', static fn(): Driver => self::claude($app, $config));

            return $registry;
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__ . '/../config/llm-evals.php' => config_path('llm-evals.php')], 'llm-evals-config');
        $this->commands([RunEvalsCommand::class]);
    }

    private static function claude(Application $app, Repository $config): ClaudeDriver
    {
        if ($app->bound(Client::class)) {
            $client = $app->make(Client::class);
        } else {
            $key = $config->get('llm-evals.claude.api_key');

            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('The claude driver needs an API key: set ANTHROPIC_API_KEY (read through config llm-evals.claude.api_key).');
            }

            $client = new Client(apiKey: $key);
        }

        $maxTokens = $config->get('llm-evals.claude.max_tokens');
        $effort = $config->get('llm-evals.claude.effort');

        if (! is_int($maxTokens)) {
            throw new InvalidArgumentException('llm-evals.claude.max_tokens must be an integer.');
        }

        if ($effort !== null && ! is_string($effort)) {
            throw new InvalidArgumentException('llm-evals.claude.effort must be a string or null.');
        }

        return new ClaudeDriver($client, self::string($config, 'llm-evals.claude.model'), $maxTokens, $effort);
    }

    private static function string(Repository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Config {$key} must be a non-empty string.");
        }

        return $value;
    }
}
