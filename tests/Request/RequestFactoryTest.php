<?php

namespace Golo\SymfonyAnthropic\Tests\Request;

use Golo\SymfonyAnthropic\Request\RequestFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestFactoryTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'Hi']];

    public function testLayersOverrideInOrderAndRecordSources(): void
    {
        $factory = new RequestFactory(
            defaults: ['model' => 'claude-opus-5-5', 'max_tokens' => 1000, 'effort' => 'low', 'temperature' => 0.5],
            models: ['claude-opus-5-5' => ['max_tokens' => 2000, 'effort' => 'medium']],
            presets: ['writer' => ['effort' => 'high']],
        );

        $request = $factory->build(self::MESSAGES, preset: 'writer', options: ['temperature' => 0.9]);

        $this->assertSame(2000, $request->params['maxTokens']);
        $this->assertSame(['effort' => 'high'], $request->params['outputConfig']);
        $this->assertSame(0.9, $request->params['temperature']);
        $this->assertSame([
            'model' => 'defaults',
            'max_tokens' => 'model: claude-opus-5-5',
            'effort' => 'preset: writer',
            'temperature' => 'call',
        ], $request->sources);
        $this->assertSame('writer', $request->preset);
    }

    public function testModelRulesFollowTheModelChosenByPresetOrCall(): void
    {
        $factory = new RequestFactory(
            defaults: ['model' => 'claude-haiku-4-5'],
            models: ['claude-opus-5-5' => ['max_tokens' => 16000]],
            presets: ['big' => ['model' => 'claude-opus-5-5']],
        );

        $this->assertSame(16000, $factory->build(self::MESSAGES, preset: 'big')->params['maxTokens']);
        $this->assertSame(16000, $factory->build(self::MESSAGES, options: ['model' => 'claude-opus-5-5'])->params['maxTokens']);
        $this->assertSame(4096, $factory->build(self::MESSAGES)->params['maxTokens'], 'built-in max_tokens default');
    }

    public function testNullInALaterLayerFallsThrough(): void
    {
        $factory = new RequestFactory(defaults: ['model' => 'm', 'effort' => 'low']);

        $request = $factory->build(self::MESSAGES, options: ['effort' => null]);

        $this->assertSame(['effort' => 'low'], $request->params['outputConfig']);
    }

    public function testSystemAndMessagesArePassedThrough(): void
    {
        $factory = new RequestFactory(defaults: ['model' => 'm']);

        $request = $factory->build(self::MESSAGES, system: 'Be brief');

        $this->assertSame(self::MESSAGES, $request->params['messages']);
        $this->assertSame('Be brief', $request->params['system']);
        $this->assertArrayNotHasKey('system', $factory->build(self::MESSAGES)->params);
    }

    public function testRequestWithoutBetaFeaturesUsesStableEndpoint(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'claude-haiku-4-5', 'thinking' => 'disabled']))->build(self::MESSAGES);

        $this->assertFalse($request->beta);
        $this->assertArrayNotHasKey('betas', $request->params);
        $this->assertSame(['type' => 'disabled'], $request->params['thinking']);
    }

    public function testDefaultFallbacksAddBetaHeader(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'm', 'fallbacks' => 'default']))->build(self::MESSAGES);

        $this->assertTrue($request->beta);
        $this->assertSame('default', $request->params['fallbacks']);
        $this->assertSame(['server-side-fallback-2026-07-01'], $request->params['betas']);
    }

    public function testFallbackListIsExpandedAndUsesArrayFormBeta(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'm', 'fallbacks' => ['claude-opus-4-8']]))->build(self::MESSAGES);

        $this->assertSame([['model' => 'claude-opus-4-8']], $request->params['fallbacks']);
        $this->assertSame(['server-side-fallback-2026-06-01'], $request->params['betas']);
    }

    public function testFalseSwitchesOffInheritedFallbacks(): void
    {
        $factory = new RequestFactory(
            defaults: ['model' => 'm'],
            models: ['m' => ['fallbacks' => 'default']],
        );

        $request = $factory->build(self::MESSAGES, options: ['fallbacks' => false]);

        $this->assertArrayNotHasKey('fallbacks', $request->params);
        $this->assertFalse($request->beta);
    }

    public function testFastModeAddsBetaHeader(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'm', 'speed' => 'fast']))->build(self::MESSAGES);

        $this->assertSame('fast', $request->params['speed']);
        $this->assertSame(['fast-mode-2026-02-01'], $request->params['betas']);
    }

    public function testBetasAccumulateAcrossLayersWithoutDuplicates(): void
    {
        $factory = new RequestFactory(
            defaults: ['model' => 'm', 'betas' => ['a']],
            models: ['m' => ['betas' => ['b'], 'fallbacks' => 'default']],
        );

        $request = $factory->build(self::MESSAGES, options: ['betas' => ['a', 'c']]);

        $this->assertSame(['a', 'b', 'c', 'server-side-fallback-2026-07-01'], $request->params['betas']);
        $this->assertSame('defaults + model: m + call', $request->sources['betas']);
    }

    public function testThinkingDisplayOnlyAppliesToAdaptive(): void
    {
        $factory = new RequestFactory(defaults: ['model' => 'm', 'thinking_display' => 'summarized']);

        $adaptive = $factory->build(self::MESSAGES, options: ['thinking' => 'adaptive']);
        $disabled = $factory->build(self::MESSAGES, options: ['thinking' => 'disabled']);

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $adaptive->params['thinking']);
        $this->assertSame(['type' => 'disabled'], $disabled->params['thinking']);
    }

    public function testThinkingUpdatesDisplayAddsBetaHeader(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'm', 'thinking' => 'adaptive', 'thinking_display' => 'updates']))->build(self::MESSAGES);

        $this->assertSame(['thinking-display-updates-2026-08-18'], $request->params['betas']);
    }

    public function testOmitSendsNoThinking(): void
    {
        $factory = new RequestFactory(defaults: ['model' => 'm', 'thinking' => 'disabled'], models: ['m' => ['thinking' => 'omit']]);

        $this->assertArrayNotHasKey('thinking', $factory->build(self::MESSAGES)->params);
    }

    #[DataProvider('alwaysThinkingModels')]
    public function testDisabledThinkingIsOmittedOnAlwaysThinkingModels(string $model): void
    {
        $request = (new RequestFactory(defaults: ['model' => $model, 'thinking' => 'disabled']))->build(self::MESSAGES);

        $this->assertArrayNotHasKey('thinking', $request->params);
        $this->assertCount(1, $request->notes);
        $this->assertStringContainsString($model, $request->notes[0]);
    }

    public static function alwaysThinkingModels(): iterable
    {
        yield ['claude-opus-5-5'];
        yield ['claude-fable-5-1'];
        yield ['claude-mythos-5-1'];
        yield 'provider prefix' => ['anthropic.claude-opus-5-5'];
    }

    public function testDisabledThinkingBecomesBetweenToolsOnSonnet55(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'claude-sonnet-5-5', 'thinking' => 'disabled']))->build(self::MESSAGES);

        $this->assertSame(['type' => 'between_tools'], $request->params['thinking']);
        $this->assertCount(1, $request->notes);
    }

    public function testDisabledThinkingIsKeptOnModelsThatAcceptIt(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'claude-opus-5', 'thinking' => 'disabled']))->build(self::MESSAGES);

        $this->assertSame(['type' => 'disabled'], $request->params['thinking']);
        $this->assertSame([], $request->notes);
    }

    public function testOptionsAreMappedToSdkParameterNames(): void
    {
        $request = (new RequestFactory(defaults: ['model' => 'm']))->build(self::MESSAGES, options: [
            'top_p' => 0.9,
            'top_k' => 40,
            'stop_sequences' => ['END'],
            'service_tier' => 'auto',
            'inference_geo' => 'us',
        ]);

        $this->assertSame(0.9, $request->params['topP']);
        $this->assertSame(40, $request->params['topK']);
        $this->assertSame(['END'], $request->params['stopSequences']);
        $this->assertSame('auto', $request->params['serviceTier']);
        $this->assertSame('us', $request->params['inferenceGeo']);
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Anthropic preset "nope". Configured presets: writer.');

        (new RequestFactory(defaults: ['model' => 'm'], presets: ['writer' => []]))->build(self::MESSAGES, preset: 'nope');
    }

    public function testUnknownOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Anthropic option(s): maxTokens.');

        (new RequestFactory(defaults: ['model' => 'm']))->build(self::MESSAGES, options: ['maxTokens' => 10]);
    }

    public function testMissingModelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No Anthropic model given');

        (new RequestFactory())->build(self::MESSAGES);
    }
}
