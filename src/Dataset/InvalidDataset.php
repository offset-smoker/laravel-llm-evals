<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

use OffsetSmoker\LlmEvals\Support\Text;
use RuntimeException;

/**
 * A dataset or recording file that cannot be used as written. The message
 * names the file and the exact location of the problem, never repeats case
 * inputs, and is stripped of control characters, so it is safe to print.
 */
final class InvalidDataset extends RuntimeException
{
    public static function at(string $file, string $path, string $problem): self
    {
        $where = $path === '' ? $file : "{$file}: {$path}";

        return new self(Text::terminalSafe("{$where} {$problem}"));
    }

    public static function file(string $file, string $problem): self
    {
        return new self(Text::terminalSafe("{$file}: {$problem}"));
    }
}
