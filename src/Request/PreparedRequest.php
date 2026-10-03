<?php

namespace Golo\SymfonyAnthropic\Request;

/**
 * A request ready for the SDK, plus how its options were resolved (shown in the profiler).
 */
final class PreparedRequest
{
    public function __construct(
        /** Named arguments for messages->create() */
        public readonly array $params,
        /** Whether the request needs the beta messages endpoint */
        public readonly bool $beta,
        public readonly ?string $preset,
        /** Resolved option values, keyed by YAML option name */
        public readonly array $options,
        /** Which layer each option came from: defaults, model: X, preset: Y or call */
        public readonly array $sources,
        /** Adjustments made for the model, e.g. thinking that can't be disabled */
        public readonly array $notes,
    ) {
    }
}
