<?php

declare(strict_types=1);

use Anthropic\Client;
use GuzzleHttp\Psr7\Request;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\ClaudeDriver;
use OffsetSmoker\LlmEvals\Drivers\DriverFailed;
use OffsetSmoker\LlmEvals\Graders\Refusal;
use OffsetSmoker\LlmEvals\Tests\Support\FakeTransport;

function claudeClient(FakeTransport $transport): Client
{
    return new Client(apiKey: 'sk-ant-test-CANARY-KEY', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);
}

function notices(): EvalCase
{
    return new EvalCase('water-main', 'Rewrite notice-101: CANARY-INPUT-7f3a', ['notice-101'], []);
}

it('sends the case through the official SDK with a cached system prompt', function (): void {
    $transport = (new FakeTransport())->replay('message-water-main.json');
    $driver = new ClaudeDriver(claudeClient($transport), 'claude-opus-5-5', 2048, 'low');

    $driver->respond(notices(), makeDataset([notices()], system: 'You rewrite notices.'));

    $request = $transport->requests[0];

    expect((string) $request->getUri())->toBe('https://api.anthropic.com/v1/messages')
        ->and($request->getMethod())->toBe('POST')
        ->and($transport->body())->toBe([
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => 'Rewrite notice-101: CANARY-INPUT-7f3a']],
            'model' => 'claude-opus-5-5',
            'output_config' => ['effort' => 'low'],
            'system' => [['type' => 'text', 'text' => 'You rewrite notices.', 'cache_control' => ['type' => 'ephemeral']]],
        ]);
});

it('omits the system prompt and effort when the dataset and config have none', function (): void {
    $transport = (new FakeTransport())->replay('message-water-main.json');

    (new ClaudeDriver(claudeClient($transport), effort: null))->respond(notices(), makeDataset([notices()]));

    expect($transport->body())->not->toHaveKeys(['system', 'output_config', 'thinking', 'fallbacks'])
        ->and($transport->body()['model'])->toBe('claude-opus-5-5')
        ->and($transport->body()['max_tokens'])->toBe(4096);
});

it('joins the text blocks, skips thinking blocks and keeps usage', function (): void {
    $transport = (new FakeTransport())->replay('message-water-main.json');

    $response = (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()]));

    expect($response->text)->toBe('The water main under Elm Street will be shut off on Tuesday at 9:00 a.m. It should be back on in six hours or less. [notice-101]')
        ->and($response->stopReason)->toBe('end_turn')
        ->and($response->model)->toBe('claude-opus-5-5')
        ->and($response->usage->toArray())->toBe(['input_tokens' => 412, 'output_tokens' => 58, 'cache_read_input_tokens' => 2048, 'cache_creation_input_tokens' => 0])
        ->and((new ClaudeDriver(claudeClient(new FakeTransport())))->name())->toBe('claude:claude-opus-5-5');
});

it('returns a refusal stop reason so the refusal grader can score it', function (): void {
    $transport = (new FakeTransport())->replay('message-refusal.json');

    $response = (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()]));

    expect($response->text)->toBe('')
        ->and($response->stopReason)->toBe('refusal')
        ->and((new Refusal(true))->grade($response, notices())->passed)->toBeTrue();
});

it('turns API errors into a DriverFailed that leaks neither the key nor the prompt', function (): void {
    $transport = (new FakeTransport())->replay('error-authentication.json', 401);

    try {
        (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()], system: 'CANARY-SYSTEM-91c2'));
    } catch (DriverFailed $e) {
        expect($e->getMessage())->toBe('Claude returned HTTP 401 authentication_error for case "water-main" (request id req_fixture_0).')
            ->and($e->getMessage())->not->toContain('CANARY')
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    test()->fail('Expected DriverFailed.');
});

it('reports server errors with their status', function (): void {
    $transport = (new FakeTransport())->replay('error-overloaded.json', 529);

    (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()]));
})->throws(DriverFailed::class, 'Claude returned HTTP 529 overloaded_error for case "water-main"');

it('reports connection failures without detail from the request', function (): void {
    $transport = (new FakeTransport())->failConnection(new Request('POST', 'https://api.anthropic.com/v1/messages'));

    (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()]));
})->throws(DriverFailed::class, 'Could not reach the Claude API for case "water-main".');

it('validates its configuration', function (string $model, int $maxTokens, ?string $effort, string $message): void {
    expect(fn() => new ClaudeDriver(claudeClient(new FakeTransport()), $model, $maxTokens, $effort))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'model with spaces' => ['claude opus', 4096, null, 'model id must look like'],
    'not a claude model' => ['gpt-x', 4096, null, 'model id must look like'],
    'zero tokens' => ['claude-opus-5-5', 0, null, 'max_tokens must be between'],
    'too many tokens' => ['claude-opus-5-5', 64_000, null, 'max_tokens must be between'],
    'unknown effort' => ['claude-opus-5-5', 4096, 'extreme', 'effort must be one of: low, medium, high, xhigh, max'],
]);

it('accepts the current model ids', function (string $model): void {
    expect((new ClaudeDriver(claudeClient(new FakeTransport()), $model))->name())->toBe("claude:{$model}");
})->with(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5']);

it('reports a malformed or incomplete API response as a failed case', function (string $fixture, string $message): void {
    $transport = (new FakeTransport())->replay($fixture);

    expect(fn() => (new ClaudeDriver(claudeClient($transport)))->respond(notices(), makeDataset([notices()])))
        ->toThrow(DriverFailed::class, $message);
})->with([
    'not JSON' => ['malformed-body.txt', 'Claude returned a response that is not valid JSON for case "water-main".'],
    'missing content and usage' => ['incomplete-message.json', 'Claude returned an incomplete message for case "water-main".'],
]);
