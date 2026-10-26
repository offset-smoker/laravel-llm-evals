<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

use OffsetSmoker\LlmEvals\Drivers\Driver;

/**
 * The falsifiability control every dataset must declare: a deliberately bad
 * baseline whose pass rate must stay at or below `ceiling`. If a fixed
 * answer, an echo of the input, or a recorded bad output passes the graders,
 * the graders are not measuring anything and the run fails.
 */
final readonly class Control
{
    public function __construct(
        public Driver $driver,
        public float $ceiling,
    ) {}
}
