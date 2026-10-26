<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use InvalidArgumentException;
use JsonException;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * Passes when the response is JSON that validates against a JSON Schema
 * (draft 2020-12 by default). One surrounding Markdown code fence is
 * tolerated, since models often add one. Schemas are resolved locally only:
 * no `$ref` is ever fetched over the network.
 */
final readonly class JsonSchema implements Grader
{
    /** Keywords whose value is a map of names to schemas, never a list. */
    private const array MAP_KEYWORDS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    private stdClass $schema;

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(array $schema)
    {
        $object = self::toObject($schema, null);

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
            $result = (new Validator())->validate($data, $this->schema);
        } catch (SchemaException $e) {
            return Grade::fail("the schema could not be applied ({$e->getMessage()})");
        }

        $error = $result->error();

        if ($error === null) {
            return Grade::pass('valid');
        }

        $messages = [];

        foreach ((new ErrorFormatter())->format($error, false) as $pointer => $message) {
            if (is_string($message)) {
                $messages[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
            }
        }

        return Grade::fail('schema violations: ' . implode('; ', array_slice($messages, 0, 3)));
    }

    /**
     * Turns a decoded PHP array back into the object form JSON Schema needs,
     * keeping `{}` for empty maps under keywords that hold maps.
     */
    private static function toObject(mixed $value, ?string $key): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return in_array($key, self::MAP_KEYWORDS, true) ? new stdClass() : [];
        }

        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::toObject($item, null), $value);
        }

        $object = new stdClass();

        foreach ($value as $name => $item) {
            $object->{(string) $name} = self::toObject($item, in_array($key, self::MAP_KEYWORDS, true) ? null : (string) $name);
        }

        return $object;
    }
}
