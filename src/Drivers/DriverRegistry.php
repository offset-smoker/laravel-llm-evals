<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use Closure;
use InvalidArgumentException;

/**
 * Named driver factories. The service provider registers `claude` and
 * `recorded`; register your own feature in a service provider's `boot()`:
 *
 *     $this->app->make(DriverRegistry::class)->extend('summarizer', fn () => new CallbackDriver(
 *         fn (EvalCase $case) => app(Summarizer::class)->summarize($case->input),
 *         'summarizer',
 *     ));
 */
final class DriverRegistry
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/D';

    /** @var array<string, Closure(): Driver> */
    private array $factories = [];

    /**
     * @param  Closure(): Driver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Driver name \"{$name}\" must be lowercase letters, digits, \"-\" or \"_\".");
        }

        $this->factories[$name] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    public function make(string $name): Driver
    {
        $factory = $this->factories[$name]
            ?? throw new InvalidArgumentException("Unknown driver \"{$name}\" (registered: " . implode(', ', $this->names()) . ').');

        return $factory();
    }
}
