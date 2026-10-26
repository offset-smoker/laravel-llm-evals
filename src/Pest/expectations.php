<?php

declare(strict_types=1);

use OffsetSmoker\LlmEvals\Pest\EvalExpectations;
use Pest\Expectation;

// Registers the Pest expectations only when Pest is installed, so requiring
// this package never depends on Pest at runtime.
if (class_exists(Expectation::class)) {
    EvalExpectations::register();
}
