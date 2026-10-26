<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Dataset;

/**
 * A golden dataset loaded and validated from a JSON or YAML file.
 */
final readonly class Dataset
{
    /**
     * @param  list<EvalCase>  $cases
     */
    public function __construct(
        public string $name,
        public float $threshold,
        public ?string $system,
        public Control $control,
        public array $cases,
        public string $file,
    ) {}
}
