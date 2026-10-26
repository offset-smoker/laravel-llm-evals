<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use JsonException;
use OffsetSmoker\LlmEvals\Dataset\InvalidDataset;
use OffsetSmoker\LlmEvals\Support\Spec;
use RuntimeException;

/**
 * Reads and writes recording files: the saved responses of one subject run,
 * keyed by case id, so later runs can be graded without calling a model.
 *
 *     {"dataset": "notices", "responses": {"case-1": {"text": "...", "stop_reason": "end_turn", "model": "claude-opus-5-5"}}}
 */
final class Recording
{
    public const int MAX_BYTES = 20_000_000;

    public const int MAX_TEXT_LENGTH = 500_000;

    /**
     * @return array<string, Response>
     */
    public static function read(string $file, string $dataset): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw InvalidDataset::file($file, 'recording not found or not readable.');
        }

        $size = filesize($file);

        if ($size === false || $size > self::MAX_BYTES) {
            throw InvalidDataset::file($file, 'recording is larger than ' . self::MAX_BYTES . ' bytes.');
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw InvalidDataset::file($file, 'recording could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidDataset::file($file, "recording is not valid JSON ({$e->getMessage()}).");
        }

        $root = Spec::root($decoded, $file);
        $root->onlyKeys('dataset', 'responses');

        if ($root->string('dataset', 100) !== $dataset) {
            $root->fail('dataset', "must be \"{$dataset}\", the dataset this recording is replayed for.");
        }

        $responses = [];
        $map = $root->map('responses');

        foreach ($map->keys() as $caseId) {
            $entry = $map->map($caseId);
            $entry->onlyKeys('text', 'stop_reason', 'model');

            $text = $entry->value('text');

            if (! is_string($text) || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                $entry->fail('text', 'must be a string of at most ' . self::MAX_TEXT_LENGTH . ' characters.');
            }

            $responses[$caseId] = new Response(
                $text,
                $entry->optionalString('stop_reason', 64),
                $entry->optionalString('model', 100),
            );
        }

        return $responses;
    }

    /**
     * Writes the file atomically, so an interrupted run never leaves a
     * half-written recording behind.
     *
     * @param  array<string, Response>  $responses
     */
    public static function write(string $file, string $dataset, array $responses): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the recordings directory {$directory}.");
        }

        $entries = [];

        foreach ($responses as $caseId => $response) {
            $entries[$caseId] = [
                'text' => $response->text,
                'stop_reason' => $response->stopReason,
                'model' => $response->model,
            ];
        }

        try {
            $json = json_encode(
                ['dataset' => $dataset, 'responses' => (object) $entries],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode the recording for {$dataset}: {$e->getMessage()}");
        }

        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temporary, $json . "\n") === false || ! rename($temporary, $file)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException("Could not write the recording {$file}.");
        }
    }
}
