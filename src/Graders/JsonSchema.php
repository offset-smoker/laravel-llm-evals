<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use InvalidArgumentException;
use JsonException;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Passes when the response is JSON that validates against a JSON Schema
 * (draft 2020-12 by default). One surrounding Markdown code fence is
 * tolerated, since models often add one.
 *
 * Only local references (`#/...`) are allowed, and they are checked when the
 * grader is built, so no reference is ever resolved over the network or from
 * the filesystem. Failure reasons name the violated keyword and where it sits
 * in the schema, never a value or key from the response.
 */
final readonly class JsonSchema implements Grader
{
    /** Keywords whose value is a map of names to schemas. */
    private const array SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** Keywords whose value is one schema (or, for `items` in older drafts, a list of schemas). */
    private const array SCHEMAS = [
        'items', 'additionalItems', 'additionalProperties', 'unevaluatedItems', 'unevaluatedProperties',
        'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema',
    ];

    /** Keywords whose value is a list of schemas. */
    private const array SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** Keywords that point at another schema. */
    private const array REFERENCES = ['$ref', '$dynamicRef', '$recursiveRef'];

    private stdClass $schema;

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(array $schema)
    {
        $object = self::schema($schema, '#');

        if (! $object instanceof stdClass) {
            throw new InvalidArgumentException('must be a JSON Schema object.');
        }

        try {
            (new Validator())->validate(new stdClass(), $object);
        } catch (SchemaException $e) {
            throw new InvalidArgumentException("is not a usable JSON Schema ({$e->getMessage()}).");
        }

        $this->schema = $object;
    }

    public function describe(): string
    {
        return 'is JSON matching the schema';
    }

    public function grade(Response $response, EvalCase $case): Grade
    {
        $json = trim($response->text);

        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $json, $fence) === 1) {
            $json = $fence[1];
        }

        try {
            $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return Grade::fail("not valid JSON ({$e->getMessage()})");
        }

        try {
            $error = (new Validator())->validate($data, $this->schema)->error();
        } catch (SchemaException $e) {
            return Grade::fail("the schema could not be applied ({$e->getMessage()})");
        }

        if ($error === null) {
            return Grade::pass('valid');
        }

        $violations = array_values(array_unique(self::violations($error)));

        return Grade::fail('schema violations: ' . implode('; ', array_slice($violations, 0, 5)));
    }

    /**
     * The leaf errors as "keyword at schema location", e.g. "pattern at #/properties/date".
     *
     * @return list<string>
     */
    private static function violations(ValidationError $error): array
    {
        $children = array_filter($error->subErrors(), static fn(mixed $child): bool => $child instanceof ValidationError);

        if ($children === []) {
            $segments = array_map(static fn(mixed $segment): string => is_scalar($segment) ? (string) $segment : '?', $error->schema()->info()->path());
            $location = '#' . ($segments === [] ? '' : '/' . implode('/', $segments));

            return ["{$error->keyword()} at {$location}"];
        }

        $violations = [];

        foreach ($children as $child) {
            $violations = [...$violations, ...self::violations($child)];
        }

        return $violations;
    }

    /**
     * Converts a value in schema position from its decoded PHP form back to
     * the object form JSON Schema needs. Decoding loses the difference
     * between `{}` and `[]`, so an empty array in schema position becomes
     * `{}` (the schema that accepts anything).
     */
    private static function schema(mixed $value, string $at): mixed
    {
        if ($value === []) {
            return new stdClass();
        }

        if (! is_array($value) || array_is_list($value)) {
            return self::plain($value);
        }

        $object = new stdClass();

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (in_array($key, self::REFERENCES, true) && (! is_string($item) || ! str_starts_with($item, '#'))) {
                throw new InvalidArgumentException("uses a non-local {$key} at {$at}; only references inside the schema (\"#/...\") are allowed.");
            }

            $object->{$key} = match (true) {
                in_array($key, self::SCHEMA_MAPS, true) => self::schemaMap($item, "{$at}/{$key}"),
                in_array($key, self::SCHEMAS, true) && is_array($item) && $item !== [] && array_is_list($item) => self::schemaList($item, "{$at}/{$key}"),
                in_array($key, self::SCHEMAS, true) => self::schema($item, "{$at}/{$key}"),
                in_array($key, self::SCHEMA_LISTS, true) && is_array($item) => self::schemaList($item, "{$at}/{$key}"),
                default => self::plain($item),
            };
        }

        return $object;
    }

    private static function schemaMap(mixed $value, string $at): mixed
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return self::plain($value);
        }

        $object = new stdClass();

        foreach ($value as $name => $item) {
            $object->{(string) $name} = self::schema($item, "{$at}/{$name}");
        }

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<mixed>
     */
    private static function schemaList(array $value, string $at): array
    {
        $list = [];

        foreach (array_values($value) as $index => $item) {
            $list[] = self::schema($item, "{$at}/{$index}");
        }

        return $list;
    }

    /**
     * Converts a plain JSON value (an `enum`, a `const`, a `default`).
     */
    private static function plain(mixed $value): mixed
    {
        if (! is_array($value) || $value === []) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::plain(...), $value);
        }

        $object = new stdClass();

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::plain($item);
        }

        return $object;
    }
}
