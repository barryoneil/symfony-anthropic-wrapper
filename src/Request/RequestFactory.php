<?php

namespace Golo\SymfonyAnthropic\Request;

/**
 * Turns configured defaults, per-model rules, presets and per-call options into
 * named arguments for the SDK's messages->create().
 *
 * Precedence (later wins): defaults < models[model] < presets[preset] < call options.
 * A null value means "not set here" and falls through to the earlier layer.
 */
class RequestFactory
{
    public const OPTION_KEYS = [
        'model',
        'max_tokens',
        'thinking',
        'thinking_display',
        'effort',
        'fallbacks',
        'speed',
        'temperature',
        'top_p',
        'top_k',
        'stop_sequences',
        'service_tier',
        'inference_geo',
        'betas',
    ];

    private const BETA_FALLBACK_DEFAULT = 'server-side-fallback-2026-07-01';
    private const BETA_FALLBACK_LIST = 'server-side-fallback-2026-06-01';
    private const BETA_FAST_MODE = 'fast-mode-2026-02-01';
    private const BETA_THINKING_UPDATES = 'thinking-display-updates-2026-08-18';

    // Models where thinking is always on: 'disabled' returns a 400, so it is omitted instead
    private const ALWAYS_THINKING = ['claude-opus-5-5', 'claude-fable-5', 'claude-mythos-5'];
    // Models where 'disabled' returns a 400 but 'between_tools' turns thinking off
    private const BETWEEN_TOOLS_INSTEAD_OF_DISABLED = ['claude-sonnet-5-5'];

    public function __construct(
        private array $defaults = [],
        private array $models = [],
        private array $presets = [],
    ) {
    }

    public function build(array $messages, string|array|null $system = null, ?string $preset = null, array $options = []): PreparedRequest
    {
        [$resolved, $sources] = $this->resolve($preset, $options);
        $model = $resolved['model'];
        $notes = [];

        $params = [
            'model' => $model,
            'maxTokens' => (int) $resolved['max_tokens'],
            'messages' => $messages,
        ];
        if (null !== $system) {
            $params['system'] = $system;
        }

        $betas = (array) ($resolved['betas'] ?? []);

        $thinking = $this->thinkingFor($model, $resolved['thinking'], $notes);
        if (null !== $thinking) {
            if ('adaptive' === $thinking['type'] && null !== $resolved['thinking_display']) {
                $thinking['display'] = $resolved['thinking_display'];
                if ('updates' === $resolved['thinking_display']) {
                    $betas[] = self::BETA_THINKING_UPDATES;
                }
            }
            $params['thinking'] = $thinking;
        }

        if (null !== $resolved['effort']) {
            $params['outputConfig'] = ['effort' => $resolved['effort']];
        }

        if (null !== $resolved['fallbacks'] && false !== $resolved['fallbacks']) {
            if (\is_string($resolved['fallbacks'])) {
                $params['fallbacks'] = $resolved['fallbacks'];
                $betas[] = self::BETA_FALLBACK_DEFAULT;
            } else {
                $params['fallbacks'] = array_map(
                    fn ($fallback) => \is_string($fallback) ? ['model' => $fallback] : $fallback,
                    array_values($resolved['fallbacks']),
                );
                $betas[] = self::BETA_FALLBACK_LIST;
            }
        }

        if (null !== $resolved['speed']) {
            $params['speed'] = $resolved['speed'];
            $betas[] = self::BETA_FAST_MODE;
        }

        foreach ([
            'temperature' => 'temperature',
            'top_p' => 'topP',
            'top_k' => 'topK',
            'stop_sequences' => 'stopSequences',
            'service_tier' => 'serviceTier',
            'inference_geo' => 'inferenceGeo',
        ] as $key => $param) {
            if (null !== $resolved[$key]) {
                $params[$param] = $resolved[$key];
            }
        }

        $betas = array_values(array_unique($betas));
        if ([] !== $betas) {
            $params['betas'] = $betas;
        }

        return new PreparedRequest(
            params: $params,
            beta: [] !== $betas,
            preset: $preset,
            options: $resolved,
            sources: $sources,
            notes: $notes,
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>} resolved options and the layer each came from
     */
    public function resolve(?string $preset = null, array $options = []): array
    {
        if (null !== $preset && !isset($this->presets[$preset])) {
            throw new \InvalidArgumentException(\sprintf('Unknown Anthropic preset "%s". Configured presets: %s.', $preset, implode(', ', array_keys($this->presets)) ?: 'none'));
        }

        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown Anthropic option(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::OPTION_KEYS)));
        }

        $presetOptions = null !== $preset ? $this->presets[$preset] : [];
        $model = $options['model'] ?? $presetOptions['model'] ?? $this->defaults['model'] ?? null;
        if (null === $model) {
            throw new \InvalidArgumentException('No Anthropic model given. Set anthropic.defaults.model, a preset model, or pass the "model" option.');
        }

        $layers = ['defaults' => $this->defaults];
        if (isset($this->models[$model])) {
            $layers['model: '.$model] = $this->models[$model];
        }
        if (null !== $preset) {
            $layers['preset: '.$preset] = $presetOptions;
        }
        $layers['call'] = $options;

        $resolved = array_fill_keys(self::OPTION_KEYS, null);
        $sources = [];
        foreach ($layers as $layer => $values) {
            foreach (self::OPTION_KEYS as $key) {
                if (!\array_key_exists($key, $values) || null === $values[$key]) {
                    continue;
                }
                // Beta headers accumulate across layers; everything else overrides
                if ('betas' === $key) {
                    $resolved['betas'] = array_merge((array) $resolved['betas'], (array) $values['betas']);
                    $sources['betas'] = isset($sources['betas']) ? $sources['betas'].' + '.$layer : $layer;
                    continue;
                }
                $resolved[$key] = $values[$key];
                $sources[$key] = $layer;
            }
        }

        $resolved['model'] = $model;
        $resolved['max_tokens'] ??= 4096;

        return [$resolved, $sources];
    }

    public function getPresetNames(): array
    {
        return array_keys($this->presets);
    }

    private function thinkingFor(string $model, ?string $thinking, array &$notes): ?array
    {
        if (null === $thinking || 'omit' === $thinking) {
            return null;
        }

        if ('disabled' === $thinking) {
            if ($this->modelMatches($model, self::ALWAYS_THINKING)) {
                $notes[] = \sprintf('Thinking can\'t be disabled on %s, so it was left out. Use a lower effort to reduce it.', $model);

                return null;
            }
            if ($this->modelMatches($model, self::BETWEEN_TOOLS_INSTEAD_OF_DISABLED)) {
                $notes[] = \sprintf('%s rejects thinking "disabled", so "between_tools" was sent instead.', $model);

                return ['type' => 'between_tools'];
            }
        }

        return ['type' => $thinking];
    }

    private function modelMatches(string $model, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            // str_contains so provider-prefixed IDs (e.g. "anthropic.claude-opus-5-5") match too
            if (str_contains($model, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
