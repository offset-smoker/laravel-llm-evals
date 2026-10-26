<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client for the Anthropic SDK that replays recorded HTTP
 * responses in order and keeps every request, so tests exercise the real
 * SDK request building and response parsing without a network call.
 */
final class FakeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|NetworkExceptionInterface> */
    private array $queue = [];

    public function replay(string $fixture, int $status = 200): self
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/claude/' . $fixture);

        if ($body === false) {
            throw new RuntimeException("Missing fixture {$fixture}");
        }

        $this->queue[] = new Response($status, ['content-type' => 'application/json', 'request-id' => 'req_fixture_' . count($this->queue)], $body);

        return $this;
    }

    public function failConnection(RequestInterface $placeholder): self
    {
        $this->queue[] = new class ('Connection refused', $placeholder) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new RuntimeException('FakeTransport has no recorded response left for ' . $request->getUri());

        if ($next instanceof NetworkExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        $decoded = json_decode((string) $this->requests[$index]->getBody(), true, 64, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
