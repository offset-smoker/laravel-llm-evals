<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use RuntimeException;

/**
 * A driver could not produce output for a case. The message is safe to print
 * and to log: it never contains the prompt, the case input, or credentials.
 */
final class DriverFailed extends RuntimeException {}
