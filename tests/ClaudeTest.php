<?php

namespace Golo\SymfonyAnthropic\Tests;

use Anthropic\Client;
use Golo\SymfonyAnthropic\Claude;
use Golo\SymfonyAnthropic\Request\RequestFactory;
use PHPUnit\Framework\TestCase;

class ClaudeTest extends TestCase
{
    private Claude $claude;

    protected function setUp(): void
    {
        $this->claude = new Claude(
            new Client(apiKey: 'test'),
            new RequestFactory(defaults: ['model' => 'claude-opus-5-5']),
            cacheTtl: '1h',
        );
    }

    public function testTextWithoutCache(): void
    {
        $this->assertSame(['type' => 'text', 'text' => 'Hi'], $this->claude->text('Hi'));
    }

    public function testTextCacheUsesConfiguredTtl(): void
    {
        $this->assertSame(
            ['type' => 'text', 'text' => 'Hi', 'cacheControl' => ['type' => 'ephemeral', 'ttl' => '1h']],
            $this->claude->text('Hi', cache: true),
        );
    }

    public function testTextCacheAcceptsExplicitTtl(): void
    {
        $this->assertSame('5m', $this->claude->text('Hi', cache: '5m')['cacheControl']['ttl']);
    }

    public function testUserTurnsStringsIntoTextBlocksAndKeepsBlocks(): void
    {
        $cached = $this->claude->text('Context', cache: true);

        $this->assertSame([
            'role' => 'user',
            'content' => [$cached, ['type' => 'text', 'text' => 'Question']],
        ], $this->claude->user($cached, 'Question'));
    }

    public function testAssistantMessage(): void
    {
        $this->assertSame(
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Earlier answer']]],
            $this->claude->assistant('Earlier answer'),
        );
    }

    public function testPrepareBuildsWithoutSending(): void
    {
        $request = $this->claude->prepare([$this->claude->user('Hi')], options: ['effort' => 'high']);

        $this->assertSame('claude-opus-5-5', $request->params['model']);
        $this->assertSame(['effort' => 'high'], $request->params['outputConfig']);
    }
}
