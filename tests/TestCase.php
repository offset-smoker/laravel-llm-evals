<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Tests;

use OffsetSmoker\LlmEvals\LlmEvalsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LlmEvalsServiceProvider::class];
    }
}
