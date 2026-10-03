<?php

namespace Golo\SymfonyAnthropic\Tests;

use Anthropic\Messages\Message;
use Golo\SymfonyAnthropic\Request\PreparedRequest;
use Golo\SymfonyAnthropic\Result;
use PHPUnit\Framework\TestCase;

class ResultTest extends TestCase
{
    public function testTextSkipsThinkingAndJoinsTextBlocks(): void
    {
        $result = $this->makeResult([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Planning', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Once upon ', 'citations' => null],
                ['type' => 'text', 'text' => 'a time', 'citations' => null],
            ],
        ]);

        $this->assertSame('Once upon a time', $result->text());
        $this->assertSame('Planning', $result->thinking());
        $this->assertFalse($result->isRefusal());
        $this->assertNull($result->refusalCategory());
    }

    public function testRefusal(): void
    {
        $result = $this->makeResult([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined'],
        ]);

        $this->assertTrue($result->isRefusal());
        $this->assertNull($result->text());
        $this->assertSame('cyber', $result->refusalCategory());
        $this->assertSame('Declined', $result->refusalExplanation());
    }

    public function testTruncatedAndModel(): void
    {
        $result = $this->makeResult(['stop_reason' => 'max_tokens', 'model' => 'claude-opus-4-8']);

        $this->assertTrue($result->isTruncated());
        $this->assertSame('claude-opus-4-8', $result->model());
        $this->assertSame(10, $result->usage()->inputTokens);
    }

    private function makeResult(array $overrides): Result
    {
        $message = Message::fromArray($overrides + [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => 'Hi', 'citations' => null]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        return new Result($message, new PreparedRequest([], false, null, [], [], []));
    }
}
