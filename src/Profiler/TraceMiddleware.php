<?php

namespace Golo\SymfonyAnthropic\Profiler;

use Anthropic\Middleware;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records every Anthropic SDK HTTP attempt (including SDK retries) for the profiler.
 * Pass it to the client via `requestOptions: ['middleware' => [$traceMiddleware]]`.
 */
class TraceMiddleware implements Middleware, ResetInterface
{
    private const REDACTED_HEADERS = ['x-api-key', 'authorization'];

    private array $calls = [];
    private ?array $context = null;

    public function __construct(
        private bool $enabled = true,
        private ?Stopwatch $stopwatch = null,
    ) {
    }

    public function handle(RequestInterface $request, \Closure $callNext): ResponseInterface
    {
        if (!$this->enabled) {
            return $callNext($request);
        }

        $call = [
            'startedAt' => microtime(true),
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'retry' => (int) $request->getHeaderLine('X-Stainless-Retry-Count'),
            'requestHeaders' => $this->redactHeaders($request->getHeaders()),
            'requestBody' => $this->readBody($request),
            'status' => null,
            'responseHeaders' => [],
            'responseBody' => null,
            'error' => null,
            'duration' => 0.0,
            'context' => $this->context,
        ];

        $event = $this->stopwatch?->start('anthropic '.$request->getUri()->getPath(), 'anthropic');

        try {
            $response = $callNext($request);
        } catch (\Throwable $e) {
            $call['error'] = $e::class.': '.$e->getMessage();
            throw $e;
        } finally {
            $call['duration'] = (microtime(true) - $call['startedAt']) * 1000;
            $event?->stop();
            if (null !== $call['error']) {
                $this->calls[] = $call;
            }
        }

        $call['status'] = $response->getStatusCode();
        $call['responseHeaders'] = $response->getHeaders();
        // Streaming (SSE) bodies can't be rewound, so leave them unread
        if (!str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $call['responseBody'] = $this->readBody($response);
        }

        $this->calls[] = $call;

        return $response;
    }

    /**
     * Extra details (preset, resolved options) attached to calls until cleared with null.
     * Set by the Claude service around each request, so SDK retries share it.
     */
    public function setContext(?array $context): void
    {
        $this->context = $context;
    }

    public function getCalls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->context = null;
    }

    private function readBody(MessageInterface $message): mixed
    {
        $body = $message->getBody();
        if (!$body->isSeekable()) {
            return null;
        }

        $body->rewind();
        $contents = $body->getContents();
        // The SDK reads the body after us, so put the pointer back
        $body->rewind();

        if ('' === $contents) {
            return null;
        }

        try {
            return json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $contents;
        }
    }

    private function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (\in_array(strtolower($name), self::REDACTED_HEADERS, true)) {
                $headers[$name] = ['[redacted]'];
            }
        }

        return $headers;
    }
}
