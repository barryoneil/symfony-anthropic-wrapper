<?php

namespace Golo\SymfonyAnthropic;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Messages\Message;
use Golo\SymfonyAnthropic\Request\PreparedRequest;

/**
 * The SDK's message plus convenience accessors. The SDK object is available as $result->message.
 */
final class Result
{
    public function __construct(
        public readonly Message|BetaMessage $message,
        public readonly PreparedRequest $request,
    ) {
    }

    /**
     * All text blocks joined, or null when there are none (e.g. a refusal).
     */
    public function text(): ?string
    {
        $text = $this->joinBlocks('text', 'text');

        return '' === $text ? null : $text;
    }

    /**
     * Thinking summaries, when thinking.display is "summarized" or "updates". Empty by default on newer models.
     */
    public function thinking(): ?string
    {
        $thinking = $this->joinBlocks('thinking', 'thinking');

        return '' === $thinking ? null : $thinking;
    }

    public function stopReason(): ?string
    {
        return $this->message->stopReason;
    }

    public function isRefusal(): bool
    {
        return 'refusal' === $this->message->stopReason;
    }

    public function refusalCategory(): ?string
    {
        return $this->isRefusal() ? $this->message->stopDetails?->category : null;
    }

    public function refusalExplanation(): ?string
    {
        return $this->isRefusal() ? $this->message->stopDetails?->explanation : null;
    }

    /**
     * True when the response was cut off by max_tokens.
     */
    public function isTruncated(): bool
    {
        return 'max_tokens' === $this->message->stopReason;
    }

    /**
     * The model that answered. Differs from the requested model when a fallback was used.
     */
    public function model(): string
    {
        return $this->message->model;
    }

    public function usage(): object
    {
        return $this->message->usage;
    }

    private function joinBlocks(string $type, string $property): string
    {
        $parts = [];
        foreach ($this->message->content as $block) {
            if ($block->type === $type) {
                $parts[] = $block->{$property};
            }
        }

        return implode('', $parts);
    }
}
