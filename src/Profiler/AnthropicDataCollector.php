<?php

namespace Golo\SymfonyAnthropic\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AnthropicDataCollector extends AbstractDataCollector
{
    public const NAME = 'anthropic';

    private const USAGE_KEYS = [
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
    ];

    // Request fields shown as prompt content rather than as parameters
    private const CONTENT_KEYS = ['system', 'messages', 'tools'];

    public function __construct(
        private TraceMiddleware $traceMiddleware,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $calls = [];
        $totals = array_fill_keys(self::USAGE_KEYS, 0);
        $totalTime = 0.0;
        $errorCount = 0;
        $models = [];

        foreach ($this->traceMiddleware->getCalls() as $call) {
            $requestBody = \is_array($call['requestBody']) ? $call['requestBody'] : [];
            $responseBody = \is_array($call['responseBody']) ? $call['responseBody'] : [];

            $usage = [];
            foreach (self::USAGE_KEYS as $key) {
                $usage[$key] = (int) ($responseBody['usage'][$key] ?? 0);
                $totals[$key] += $usage[$key];
            }

            $stopReason = $responseBody['stop_reason'] ?? null;
            $isError = null !== $call['error'] || null === $call['status'] || $call['status'] >= 400 || 'refusal' === $stopReason;
            $errorCount += $isError ? 1 : 0;
            $totalTime += $call['duration'];

            $model = $responseBody['model'] ?? ($requestBody['model'] ?? null);
            if (null !== $model) {
                $models[$model] = ($models[$model] ?? 0) + 1;
            }

            $calls[] = [
                'method' => $call['method'],
                'url' => $call['url'],
                'path' => parse_url($call['url'], \PHP_URL_PATH),
                'startedAt' => (int) $call['startedAt'],
                'retry' => $call['retry'],
                'model' => $model,
                'requestedModel' => $requestBody['model'] ?? null,
                'status' => $call['status'],
                'requestId' => $this->header($call['responseHeaders'], 'request-id'),
                'betas' => $this->header($call['requestHeaders'], 'anthropic-beta'),
                'duration' => $call['duration'],
                'stopReason' => $stopReason,
                'stopDetails' => $responseBody['stop_details'] ?? null,
                'error' => $call['error'] ?? ($responseBody['error']['message'] ?? null),
                'errorType' => $responseBody['error']['type'] ?? null,
                'isError' => $isError,
                'usage' => $usage,
                'serviceTier' => $responseBody['usage']['service_tier'] ?? null,
                'params' => array_diff_key($requestBody, array_flip(self::CONTENT_KEYS)),
                'system' => $this->normaliseBlocks($requestBody['system'] ?? []),
                'messages' => array_map(
                    fn (array $message) => [
                        'role' => $message['role'] ?? '?',
                        'blocks' => $this->normaliseBlocks($message['content'] ?? []),
                    ],
                    $requestBody['messages'] ?? [],
                ),
                'tools' => array_map(fn (array $tool) => $tool['name'] ?? $tool['type'] ?? '?', $requestBody['tools'] ?? []),
                'output' => $this->normaliseBlocks($responseBody['content'] ?? []),
                'rateLimits' => $this->rateLimits($call['responseHeaders']),
                'preset' => $call['context']['preset'] ?? null,
                'options' => $this->optionRows($call['context']),
                'notes' => $call['context']['notes'] ?? [],
                'viaWrapper' => null !== $call['context'],
                'requestHeaders' => $this->cloneVar($call['requestHeaders']),
                'requestBody' => $this->cloneVar($call['requestBody']),
                'responseHeaders' => $this->cloneVar($call['responseHeaders']),
                'responseBody' => $this->cloneVar($call['responseBody']),
            ];
        }

        $cacheable = $totals['input_tokens'] + $totals['cache_creation_input_tokens'] + $totals['cache_read_input_tokens'];

        $this->data = [
            'calls' => $calls,
            'totals' => $totals,
            'totalTime' => $totalTime,
            'errorCount' => $errorCount,
            'models' => $models,
            'cacheHitRatio' => $cacheable > 0 ? $totals['cache_read_input_tokens'] / $cacheable : null,
        ];
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getTemplate(): ?string
    {
        return '@Anthropic/Collector/anthropic.html.twig';
    }

    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    public function getCallCount(): int
    {
        return \count($this->getCalls());
    }

    public function getTotals(): array
    {
        return $this->data['totals'] ?? array_fill_keys(self::USAGE_KEYS, 0);
    }

    public function getTotalTokens(): int
    {
        return array_sum($this->getTotals());
    }

    public function getTotalTime(): float
    {
        return $this->data['totalTime'] ?? 0.0;
    }

    public function getErrorCount(): int
    {
        return $this->data['errorCount'] ?? 0;
    }

    public function getModels(): array
    {
        return $this->data['models'] ?? [];
    }

    public function getCacheHitRatio(): ?float
    {
        return $this->data['cacheHitRatio'] ?? null;
    }

    /**
     * Flattens string or block-array content into a list of displayable blocks.
     */
    private function normaliseBlocks(string|array $content): array
    {
        if (\is_string($content)) {
            return [['type' => 'text', 'text' => $content, 'cache' => null, 'extra' => null]];
        }

        $blocks = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? '?';
            $text = match ($type) {
                'text' => $block['text'] ?? '',
                'thinking' => $block['thinking'] ?? '',
                'redacted_thinking' => '[redacted thinking]',
                'tool_use', 'server_tool_use' => ($block['name'] ?? '?').' '.json_encode($block['input'] ?? [], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                'tool_result' => \is_string($block['content'] ?? null) ? $block['content'] : json_encode($block['content'] ?? null, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                default => null,
            };

            $cache = $block['cache_control'] ?? null;
            $blocks[] = [
                'type' => $type,
                'text' => $text,
                'cache' => null === $cache ? null : ($cache['ttl'] ?? '5m'),
                // Anything we don't render as text (images, documents...) is shown as JSON, minus large base64 data
                'extra' => null === $text ? $this->stripBinary($block) : null,
            ];
        }

        return $blocks;
    }

    private function stripBinary(array $block): string
    {
        array_walk_recursive($block, function (&$value, $key) {
            if ('data' === $key && \is_string($value) && \strlen($value) > 200) {
                $value = '[base64, '.\strlen($value).' chars]';
            }
        });

        return json_encode($block, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolved options with the config layer each came from, for options that were set.
     */
    private function optionRows(?array $context): array
    {
        $rows = [];
        foreach ($context['options'] ?? [] as $key => $value) {
            if (null === $value) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'value' => \is_scalar($value) ? var_export($value, true) : json_encode($value, \JSON_UNESCAPED_SLASHES),
                'source' => $context['sources'][$key] ?? 'built-in default',
            ];
        }

        return $rows;
    }

    private function rateLimits(array $headers): array
    {
        $limits = [];
        foreach ($headers as $name => $values) {
            $name = strtolower($name);
            if (str_starts_with($name, 'anthropic-ratelimit-') || 'retry-after' === $name) {
                $limits[substr($name, \strlen('anthropic-ratelimit-')) ?: $name] = implode(', ', $values);
            }
        }
        ksort($limits);

        return $limits;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strtolower($key) === $name) {
                return implode(', ', $values);
            }
        }

        return null;
    }
}
