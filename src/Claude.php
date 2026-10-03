<?php

namespace Golo\SymfonyAnthropic;

use Anthropic\Client;
use Golo\SymfonyAnthropic\Profiler\TraceMiddleware;
use Golo\SymfonyAnthropic\Request\PreparedRequest;
use Golo\SymfonyAnthropic\Request\RequestFactory;

/**
 * Sends messages through the Anthropic SDK using the options configured under `anthropic:`.
 *
 *     $result = $claude->create(
 *         messages: [$claude->user($claude->text($context, cache: true), 'Write page 2')],
 *         system: [$claude->text($systemPrompt, cache: true)],
 *         preset: 'story_writer',
 *     );
 *     $result->text();
 *
 * SDK exceptions (Anthropic\Core\Exceptions\*) are not caught here.
 */
class Claude
{
    public function __construct(
        private Client $client,
        private RequestFactory $requestFactory,
        private string $cacheTtl = '5m',
        private ?TraceMiddleware $traceMiddleware = null,
    ) {
    }

    /**
     * @param list<array>       $messages user/assistant messages; see user() and assistant()
     * @param string|list<array>|null $system   system prompt, as a string or text blocks
     * @param string|null       $preset   a preset name from anthropic.presets
     * @param array<string, mixed> $options per-call overrides, using the same keys as the YAML (max_tokens, effort, ...)
     */
    public function create(array $messages, string|array|null $system = null, ?string $preset = null, array $options = []): Result
    {
        $request = $this->prepare($messages, $system, $preset, $options);

        $this->traceMiddleware?->setContext([
            'preset' => $request->preset,
            'options' => $request->options,
            'sources' => $request->sources,
            'notes' => $request->notes,
        ]);

        try {
            $message = $request->beta
                ? $this->client->beta->messages->create(...$request->params)
                : $this->client->messages->create(...$request->params);
        } finally {
            $this->traceMiddleware?->setContext(null);
        }

        return new Result($message, $request);
    }

    /**
     * Builds the request without sending it, e.g. to check which options a preset resolves to.
     */
    public function prepare(array $messages, string|array|null $system = null, ?string $preset = null, array $options = []): PreparedRequest
    {
        return $this->requestFactory->build($messages, $system, $preset, $options);
    }

    /**
     * A text content block. $cache: true uses the configured anthropic.cache_ttl, or pass '5m' / '1h'.
     */
    public function text(string $text, bool|string $cache = false): array
    {
        $block = ['type' => 'text', 'text' => $text];
        if (false !== $cache) {
            $block['cacheControl'] = ['type' => 'ephemeral', 'ttl' => true === $cache ? $this->cacheTtl : $cache];
        }

        return $block;
    }

    /**
     * A user message. Each argument is a string (becomes a text block) or a content block.
     */
    public function user(string|array ...$content): array
    {
        return $this->message('user', $content);
    }

    /**
     * An assistant message, for passing earlier turns back in.
     */
    public function assistant(string|array ...$content): array
    {
        return $this->message('assistant', $content);
    }

    /**
     * The underlying SDK client, for anything the wrapper doesn't cover (streaming, batches, models, files...).
     */
    public function client(): Client
    {
        return $this->client;
    }

    private function message(string $role, array $content): array
    {
        return [
            'role' => $role,
            'content' => array_map(fn ($block) => \is_string($block) ? $this->text($block) : $block, array_values($content)),
        ];
    }
}
