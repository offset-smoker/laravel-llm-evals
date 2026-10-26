<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

use RuntimeException;

/**
 * A dataset or recording file that cannot be used as written. The message
 * names the file and the exact location of the problem, and never repeats
 * case inputs.
 */
final class InvalidDataset extends RuntimeException
{
    public static function at(string $file, string $path, string $problem): self
    {
        $where = $path === '' ? $file : "{$file}: {$path}";

        return new self("{$where} {$problem}");
    }

    public static function file(string $file, string $problem): self
    {
        return new self("{$file}: {$problem}");
    }
}
