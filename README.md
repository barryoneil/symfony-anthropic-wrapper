# Symfony Anthropic Wrapper

A Symfony bundle for the official [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php) (`anthropic-ai/sdk`):

- **The SDK client as a service:** `Anthropic\Client` is autowirable and configured from YAML.
- **Options in YAML:** request options (model, max tokens, thinking, effort, fallbacks...) set as global defaults, per-model rules and named presets, instead of hard-coding them.
- **A `Claude` service:** sends requests with those options applied, adds the right beta headers automatically, and handles model quirks (for example, thinking that can't be disabled).
- **A profiler panel:** every Claude call with its prompt, response, token usage, cache hits, rate limits, and which config layer each option came from.

## Install

```bash
composer require golo/symfony-anthropic-wrapper
```

```php
// config/bundles.php
Golo\SymfonyAnthropic\AnthropicBundle::class => ['all' => true],
```

Set `ANTHROPIC_API_KEY` in `.env.local`.

## Configuration

```yaml
# config/packages/anthropic.yaml
anthropic:
    api_key: '%env(default::ANTHROPIC_API_KEY)%'   # default
    base_url: ~
    max_retries: ~        # SDK default 2
    timeout: ~            # seconds, SDK default 600
    cache_ttl: 5m         # 5m | 1h, used by Claude::text($text, cache: true)
    profiler:
        enabled: ~        # defaults to kernel.debug

    # 1. Applied to every request
    defaults:
        model: claude-opus-5-5
        max_tokens: 4096

    # 2. Applied when that model is used
    models:
        claude-opus-5-5:
            effort: low
            max_tokens: 16000
            fallbacks: default
        claude-sonnet-5-5:
            thinking: between_tools

    # 3. Named option sets, chosen per call
    presets:
        story_writer:
            model: claude-opus-5-5
            effort: medium
        classifier:
            model: claude-haiku-4-5
            max_tokens: 256
```

Later layers win: **defaults < models < presets < per-call options**. An unset (null) option falls through to the earlier layer. The exception is `betas`, which accumulate across layers.

### Request options

These are available in `defaults`, `models.*`, `presets.*` and per call:

| Option | Values | Notes |
|---|---|---|
| `model` | model ID | |
| `max_tokens` | int | Built-in default 4096. Thinking tokens count against it. |
| `thinking` | `adaptive`, `disabled`, `between_tools`, `omit` | `omit` sends nothing. `disabled` is adjusted for models that reject it (see below). |
| `thinking_display` | `summarized`, `omitted`, `updates` | Only applies when `thinking: adaptive`. `updates` adds its beta header. |
| `effort` | `low`, `medium`, `high`, `xhigh`, `max` | Sent as `output_config.effort` |
| `fallbacks` | `default`, a list of model IDs, or `false` | Server-side refusal fallbacks. The beta header is added for you. `false` turns off an inherited value. |
| `speed` | `fast`, `standard` | Fast mode. The beta header is added for you. |
| `temperature`, `top_p`, `top_k` | numbers | Rejected by many newer models |
| `stop_sequences` | list of strings | |
| `service_tier`, `inference_geo` | string | |
| `betas` | list of strings | Extra beta headers |

**Model adjustments:** with `thinking: disabled`, the API would return a 400 error on some models, so the wrapper changes it:
- On Claude Opus 5.5, Fable 5.x and Mythos 5.x, thinking is always on, so it's left out. Use `effort` to control it.
- On Claude Sonnet 5.5, `between_tools` is sent instead.

Each adjustment is shown as a note in the profiler.

Requests that need a beta header go to the beta messages endpoint. All others go to the stable endpoint.

## Usage

```php
use Golo\SymfonyAnthropic\Claude;

class StoryWriter
{
    public function __construct(private Claude $claude) {}

    public function write(string $context, string $instruction): ?string
    {
        $result = $this->claude->create(
            messages: [
                $this->claude->user(
                    $this->claude->text($context, cache: true),   // cached prefix
                    $instruction,                                  // strings become text blocks
                ),
            ],
            system: [$this->claude->text('You are a storyteller.', cache: true)],
            preset: 'story_writer',
            options: ['max_tokens' => 8000],                      // per-call override
        );

        if ($result->isRefusal()) {
            // $result->refusalCategory(), $result->refusalExplanation()
            return null;
        }

        return $result->text();
    }
}
```

**`Result` methods:**
- `text()`, `thinking()`, `stopReason()`, `isRefusal()`, `refusalCategory()`, `refusalExplanation()`
- `isTruncated()`, which is true when the response hit `max_tokens`
- `model()`, the model that actually answered, which differs from the one requested if a fallback was used
- `usage()`
- `$result->message`, the SDK's own `Message` / `BetaMessage`
- `$result->request`, the resolved options

`Claude::prepare(...)` builds the request without sending it, which is useful for checking what a preset resolves to.

SDK exceptions (`Anthropic\Core\Exceptions\RateLimitException`, `APIStatusException`, `APIConnectionException`...) are not caught by the wrapper.

### Using the SDK directly

The wrapper doesn't cover streaming, batches, files or models. For those, use `$claude->client()` or autowire `Anthropic\Client`. Calls made through that client still show in the profiler.

If you build your own client, pass the middleware in to keep profiling:

```php
new Anthropic\Client(requestOptions: ['middleware' => [$traceMiddleware]]); // Golo\SymfonyAnthropic\Profiler\TraceMiddleware
```

## Profiler

With `kernel.debug` on, the **Anthropic** tab shows these things.

**Toolbar:** calls, time, errors/refusals, tokens, cache write/read, and the models used.

**Totals:** calls, time, tokens, cache write/read and cache-hit %.

**Each call has these tabs:**
- **Overview:**
  - model (and fallback model, if one was used), request ID, stop reason, refusal details and token usage
  - the resolved **options, with the layer each came from**, plus any model-adjustment notes
  - the parameters sent
- **Prompt:** system prompt and messages, block by block, with cache badges
- **Response:** text, thinking and tool-use blocks
- **Rate limits:** the `anthropic-ratelimit-*` headers
- **Raw request / raw response:** full headers and JSON (API key redacted)

Each call also appears on the Performance timeline. SDK retries show as separate calls with a "retry N" badge. Streamed response bodies aren't captured, because SSE streams can't be rewound.
