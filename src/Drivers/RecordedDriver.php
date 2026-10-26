<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;

/**
 * Replays saved responses. A case with no saved response fails loudly
 * rather than being graded as empty output.
 */
final class RecordedDriver implements Driver
{
    /** @var array<string, array<string, Response>> */
    private array $loaded = [];

    private function __construct(
        private readonly ?string $directory,
        private readonly ?string $file,
    ) {}

    /**
     * Replays `<directory>/<dataset name>.json` for each dataset.
     */
    public static function fromDirectory(string $directory): self
    {
        return new self(rtrim($directory, '/\\'), null);
    }

    /**
     * Replays one recording file, whatever the dataset.
     */
    public static function fromFile(string $file): self
    {
        return new self(null, $file);
    }

    public function name(): string
    {
        return 'recorded';
    }

    public function respond(EvalCase $case, Dataset $dataset): Response
    {
        $file = $this->file ?? $this->directory . DIRECTORY_SEPARATOR . $dataset->name . '.json';

        $this->loaded[$file] ??= Recording::read($file, $dataset->name);

        return $this->loaded[$file][$case->id]
            ?? throw new DriverFailed("The recording {$file} has no response for case \"{$case->id}\".");
    }
}
