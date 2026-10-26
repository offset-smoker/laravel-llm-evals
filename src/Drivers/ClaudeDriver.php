<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\OutputConfig\Effort;
use Anthropic\Messages\TextBlock;
use InvalidArgumentException;
use JsonException;
use OffsetSmoker\LlmEvals\Dataset\Dataset;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use Throwable;

/**
 * Sends each case to Claude through the official Anthropic PHP SDK.
 *
 * The dataset's system prompt is sent as one cached block, so every case
 * after the first reads the same prefix from the prompt cache (prompts
 * shorter than the model's minimum cacheable length are simply not cached).
 * No refusal fallback is configured: an eval measures the model it names,
 * and a refusal is a result to grade, not an error to route around.
 */
final readonly class ClaudeDriver implements Driver
{
    public const int MAX_TOKENS_LIMIT = 32_000;

    private ?Effort $effort;

    public function __construct(
        private Client $client,
        private string $model = 'claude-opus-5-5',
        private int $maxTokens = 4096,
        ?string $effort = 'medium',
    ) {
        // Loose on purpose: first-party ids ("claude-opus-5-5"), Vertex ids ("claude-...@20250929") and
        // Bedrock ids ("anthropic.claude-...") all pass; spaces, quotes and control characters do not.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/D', $model) !== 1 || ! str_contains($model, 'claude')) {
            throw new InvalidArgumentException('The Claude model id must look like "claude-opus-5-5".');
        }

        if ($maxTokens < 1 || $maxTokens > self::MAX_TOKENS_LIMIT) {
            throw new InvalidArgumentException('max_tokens must be between 1 and ' . self::MAX_TOKENS_LIMIT . '.');
        }

        $this->effort = $effort === null ? null : Effort::tryFrom($effort);

        if ($effort !== null && $this->effort === null) {
            $allowed = array_map(static fn(Effort $case): string => $case->value, Effort::cases());

            throw new InvalidArgumentException('effort must be one of: ' . implode(', ', $allowed) . '.');
        }
    }

    public function name(): string
    {
        return "claude:{$this->model}";
    }

    public function respond(EvalCase $case, Dataset $dataset): Response
    {
        $system = $dataset->system === null
            ? null
            : [['type' => 'text', 'text' => $dataset->system, 'cacheControl' => ['type' => 'ephemeral']]];

        try {
            $message = $this->client->messages->create(
                maxTokens: $this->maxTokens,
                messages: [['role' => 'user', 'content' => $case->input]],
                model: $this->model,
                outputConfig: $this->effort === null ? null : ['effort' => $this->effort],
                system: $system,
            );
        } catch (APIStatusException $e) {
            $type = $e->type === null ? '' : " {$e->type->value}";
            $requestId = $e->getRequestID();
            $suffix = $requestId === null ? '' : " (request id {$requestId})";

            throw new DriverFailed("Claude returned HTTP {$e->status}{$type} for case \"{$case->id}\"{$suffix}.");
        } catch (APIException) {
            throw new DriverFailed("Could not reach the Claude API for case \"{$case->id}\".");
        } catch (Throwable $e) {
            // The SDK throws a bare JsonException for a body that is not JSON; it declares no @throws for it,
            // so it is matched here rather than in its own catch, which PHPStan reads differently per PHP version.
            if ($e instanceof JsonException) {
                throw new DriverFailed("Claude returned a response that is not valid JSON for case \"{$case->id}\".");
            }

            throw $e;
        }

        // The SDK leaves required properties uninitialised when a response omits them.
        if (! isset($message->content, $message->usage)) {
            throw new DriverFailed("Claude returned an incomplete message for case \"{$case->id}\".");
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return new Response(
            $text,
            $message->stopReason,
            $message->model,
            new Usage(
                $message->usage->inputTokens,
                $message->usage->outputTokens,
                $message->usage->cacheReadInputTokens ?? 0,
                $message->usage->cacheCreationInputTokens ?? 0,
            ),
        );
    }
}
