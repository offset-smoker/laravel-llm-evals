<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use Closure;
use InvalidArgumentException;
use OffsetSmoker\LlmEvals\Support\Spec;

/**
 * Builds graders from dataset entries. Each entry is a one-key map from the
 * grader type to its arguments:
 *
 *     - contains: "Elm Street"
 *     - reading_level: {max_grade: 8}
 *
 * Register your own types with `extend()`.
 */
final class GraderFactory
{
    /** @var array<string, Closure(Spec, string): Grader> */
    private array $types;

    public function __construct()
    {
        $this->types = [
            'contains' => static fn(Spec $entry, string $type): Grader => self::contains($entry, $type, false),
            'not_contains' => static fn(Spec $entry, string $type): Grader => self::contains($entry, $type, true),
            'regex' => static fn(Spec $entry, string $type): Grader => self::regex($entry, $type, false),
            'not_regex' => static fn(Spec $entry, string $type): Grader => self::regex($entry, $type, true),
            'json_schema' => self::jsonSchema(...),
            'citations' => self::citations(...),
            'refusal' => self::refusal(...),
            'reading_level' => self::readingLevel(...),
        ];
    }

    /**
     * @param  Closure(Spec, string): Grader  $factory  receives the entry and the type key; read arguments with `$entry->value($type)` or `$entry->map($type)`
     */
    public function extend(string $type, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1) {
            throw new InvalidArgumentException("Grader type \"{$type}\" must be snake_case.");
        }

        $this->types[$type] = $factory;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function make(Spec $entry): Grader
    {
        $keys = $entry->keys();

        if (count($keys) !== 1) {
            $entry->failHere('must have exactly one key, the grader type (one of: ' . implode(', ', $this->types()) . ').');
        }

        $type = $keys[0];
        $factory = $this->types[$type] ?? $entry->fail($type, 'is not a grader type (expected one of: ' . implode(', ', $this->types()) . ').');

        try {
            return $factory($entry, $type);
        } catch (InvalidArgumentException $e) {
            $entry->fail($type, $e->getMessage());
        }
    }

    private static function contains(Spec $entry, string $type, bool $negate): Grader
    {
        if (is_string($entry->value($type))) {
            return new Contains($entry->string($type, 10_000), negate: $negate);
        }

        $args = $entry->map($type);
        $args->onlyKeys('value', 'case_sensitive');

        return new Contains($args->string('value', 10_000), $args->optionalBool('case_sensitive') ?? false, $negate);
    }

    private static function regex(Spec $entry, string $type, bool $negate): Grader
    {
        return new Matches($entry->string($type, 2_000), $negate);
    }

    private static function jsonSchema(Spec $entry, string $type): Grader
    {
        $schema = $entry->value($type);

        if (! is_array($schema) || array_is_list($schema)) {
            $entry->fail($type, 'must be a JSON Schema object.');
        }

        /** @var array<string, mixed> $schema */
        return new JsonSchema($schema);
    }

    private static function citations(Spec $entry, string $type): Grader
    {
        if ($entry->value($type) === true) {
            return new CitationsPresent();
        }

        $args = $entry->map($type);
        $args->onlyKeys('min', 'all', 'pattern');

        return new CitationsPresent(
            $args->optionalInt('min', 1, 1_000) ?? 1,
            $args->optionalBool('all') ?? false,
            $args->optionalString('pattern', 500) ?? CitationsPresent::DEFAULT_PATTERN,
        );
    }

    private static function refusal(Spec $entry, string $type): Grader
    {
        if (is_bool($entry->value($type))) {
            return new Refusal((bool) $entry->optionalBool($type));
        }

        $args = $entry->map($type);
        $args->onlyKeys('expected', 'phrases');
        $expected = $args->optionalBool('expected') ?? $args->fail('expected', 'is required.');

        return new Refusal($expected, $args->listOfStrings('phrases', 100, 200));
    }

    private static function readingLevel(Spec $entry, string $type): Grader
    {
        if (! is_array($entry->value($type))) {
            return new ReadingLevel($entry->float($type, 0, 30));
        }

        $args = $entry->map($type);
        $args->onlyKeys('max_grade');

        return new ReadingLevel($args->float('max_grade', 0, 30));
    }
}
