<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Support;

use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;

/**
 * A strict reader over one decoded map from a dataset or recording file.
 * Every accessor validates the type and bounds of the value it returns and
 * reports problems with the file and the dotted path to the value.
 */
final readonly class Spec
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private array $data,
        public string $file,
        public string $path,
    ) {}

    public static function root(mixed $data, string $file): self
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidDataset::file($file, 'must contain a map at the top level.');
        }

        return new self(self::stringKeys($data, $file, ''), $file, '');
    }

    public function at(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    public function fail(string $key, string $problem): never
    {
        throw InvalidDataset::at($this->file, $this->at($key), $problem);
    }

    public function failHere(string $problem): never
    {
        throw InvalidDataset::at($this->file, $this->path, $problem);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function onlyKeys(string ...$allowed): void
    {
        foreach (array_keys($this->data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->fail($key, 'is not a recognised key (expected one of: ' . implode(', ', $allowed) . ').');
            }
        }
    }

    public function string(string $key, int $maxLength): string
    {
        $value = $this->optionalString($key, $maxLength);

        if ($value === null) {
            $this->fail($key, 'is required.');
        }

        return $value;
    }

    public function optionalString(string $key, int $maxLength): ?string
    {
        if (! $this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (! is_string($value) || trim($value) === '') {
            $this->fail($key, 'must be a non-empty string.');
        }

        if (mb_strlen($value) > $maxLength) {
            $this->fail($key, "must be at most {$maxLength} characters.");
        }

        return $value;
    }

    public function float(string $key, float $min, float $max): float
    {
        $value = $this->optionalFloat($key, $min, $max);

        if ($value === null) {
            $this->fail($key, 'is required.');
        }

        return $value;
    }

    public function optionalFloat(string $key, float $min, float $max): ?float
    {
        if (! $this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (! is_int($value) && ! is_float($value)) {
            $this->fail($key, 'must be a number.');
        }

        $value = (float) $value;

        if (is_nan($value) || $value < $min || $value > $max) {
            $this->fail($key, "must be between {$min} and {$max}.");
        }

        return $value;
    }

    public function optionalInt(string $key, int $min, int $max): ?int
    {
        if (! $this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (! is_int($value) || $value < $min || $value > $max) {
            $this->fail($key, "must be a whole number between {$min} and {$max}.");
        }

        return $value;
    }

    public function optionalBool(string $key): ?bool
    {
        if (! $this->has($key) || $this->data[$key] === null) {
            return null;
        }

        $value = $this->data[$key];

        if (! is_bool($value)) {
            $this->fail($key, 'must be true or false.');
        }

        return $value;
    }

    public function map(string $key): self
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($key, 'must be a map.');
        }

        return new self(self::stringKeys($value, $this->file, $this->at($key)), $this->file, $this->at($key));
    }

    /**
     * @return list<self>
     */
    public function listOfMaps(string $key, int $maxItems): array
    {
        $items = [];

        foreach ($this->list($key, $maxItems) as $index => $item) {
            $path = "{$this->at($key)}[{$index}]";

            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                throw InvalidDataset::at($this->file, $path, 'must be a map.');
            }

            $items[] = new self(self::stringKeys($item, $this->file, $path), $this->file, $path);
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function listOfStrings(string $key, int $maxItems, int $maxLength): array
    {
        if (! $this->has($key)) {
            return [];
        }

        $strings = [];

        foreach ($this->list($key, $maxItems) as $index => $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength) {
                throw InvalidDataset::at($this->file, "{$this->at($key)}[{$index}]", "must be a non-empty string of at most {$maxLength} characters.");
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @return list<mixed>
     */
    private function list(string $key, int $maxItems): array
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($key, 'must be a list.');
        }

        if (count($value) > $maxItems) {
            $this->fail($key, "must have at most {$maxItems} items.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data, string $file, string $path): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw InvalidDataset::at($file, $path, 'must use string keys.');
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
