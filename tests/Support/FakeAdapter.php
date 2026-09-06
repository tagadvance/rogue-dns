<?php

declare(strict_types=1);

namespace tagadvance\roguedns\Test\Support;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Auth\Auth;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Records every request and replays canned JSON bodies queued per method and URI.
 *
 * A queued body is matched on URI prefix and consumed by the first matching request, so
 * queueing several under one URI walks a paginated endpoint page by page.
 */
final class FakeAdapter implements Adapter
{
    /** @var list<array{method: string, uri: string, data: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, uri: string, body: array<string, mixed>}> */
    private array $queue = [];

    public function __construct(?Auth $auth = null, ?string $baseURI = null) {}

    /**
     * @param array<string, mixed> $body decoded JSON to replay, e.g. ['success' => true, 'result' => []]
     */
    public function queue(string $method, string $uri, array $body): void
    {
        $this->queue[] = ['method' => $method, 'uri' => $uri, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->respond('get', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->respond('post', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->respond('put', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->respond('patch', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->respond('delete', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(string $method, string $uri, array $data): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'uri' => $uri, 'data' => $data];

        foreach ($this->queue as $i => $queued) {
            if ($queued['method'] === $method && str_starts_with($uri, $queued['uri'])) {
                array_splice($this->queue, $i, 1);

                return new Response(200, [], json_encode($queued['body'], JSON_THROW_ON_ERROR));
            }
        }

        throw new RuntimeException("no queued response for $method $uri");
    }
}
