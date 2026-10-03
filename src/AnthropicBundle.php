<?php

namespace Golo\SymfonyAnthropic;

use Anthropic\Client;
use Golo\SymfonyAnthropic\Profiler\AnthropicDataCollector;
use Golo\SymfonyAnthropic\Profiler\TraceMiddleware;
use Golo\SymfonyAnthropic\Request\RequestFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class AnthropicBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode()->children();

        $root
            ->scalarNode('api_key')
                ->info('Null lets the SDK find credentials itself (ANTHROPIC_API_KEY, ant auth profile...).')
                ->defaultValue('%env(default::ANTHROPIC_API_KEY)%')
            ->end()
            ->scalarNode('base_url')->defaultNull()->end()
            ->integerNode('max_retries')->info('SDK default: 2')->defaultNull()->end()
            ->floatNode('timeout')->info('Seconds. SDK default: 600')->defaultNull()->end()
            ->enumNode('cache_ttl')
                ->info('TTL used by Claude::text($text, cache: true)')
                ->values(['5m', '1h'])
                ->defaultValue('5m')
            ->end()
            ->arrayNode('profiler')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->info('Defaults to kernel.debug')->defaultNull()->end()
                ->end()
            ->end();

        $this->addOptionsNode($root->arrayNode('defaults')->info('Applied to every request')->addDefaultsIfNotSet());

        $this->addOptionsNode(
            $root->arrayNode('models')
                ->info('Per-model options, keyed by model ID. Applied when that model is used.')
                ->useAttributeAsKey('model')
                ->normalizeKeys(false)
                ->arrayPrototype()
        );

        $this->addOptionsNode(
            $root->arrayNode('presets')
                ->info('Named option sets, used with Claude::create(preset: "name"). Override model options.')
                ->useAttributeAsKey('name')
                ->normalizeKeys(false)
                ->arrayPrototype()
        );
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $profilerEnabled = $config['profiler']['enabled'] ?? (bool) $builder->getParameter('kernel.debug');
        $services = $container->services();

        $services->set('anthropic.trace_middleware', TraceMiddleware::class)
            ->args([$profilerEnabled, service('debug.stopwatch')->nullOnInvalid()])
            ->tag('kernel.reset', ['method' => 'reset']);
        $services->alias(TraceMiddleware::class, 'anthropic.trace_middleware');

        $requestOptions = array_filter([
            'middleware' => [service('anthropic.trace_middleware')],
            'maxRetries' => $config['max_retries'],
            'timeout' => $config['timeout'],
        ], fn ($value) => null !== $value);

        $services->set('anthropic.client', Client::class)
            ->arg('$apiKey', $config['api_key'])
            ->arg('$baseUrl', $config['base_url'])
            ->arg('$requestOptions', $requestOptions);
        $services->alias(Client::class, 'anthropic.client');

        $services->set('anthropic.request_factory', RequestFactory::class)
            ->args([
                $this->withoutNulls($config['defaults']),
                array_map($this->withoutNulls(...), $config['models']),
                array_map($this->withoutNulls(...), $config['presets']),
            ]);
        $services->alias(RequestFactory::class, 'anthropic.request_factory');

        $services->set('anthropic.claude', Claude::class)
            ->args([
                service('anthropic.client'),
                service('anthropic.request_factory'),
                $config['cache_ttl'],
                service('anthropic.trace_middleware'),
            ]);
        $services->alias(Claude::class, 'anthropic.claude');

        if ($profilerEnabled) {
            $services->set('anthropic.data_collector', AnthropicDataCollector::class)
                ->args([service('anthropic.trace_middleware')])
                ->tag('data_collector', [
                    'id' => AnthropicDataCollector::NAME,
                    'template' => AnthropicDataCollector::getTemplate(),
                    'priority' => 250,
                ]);
        }
    }

    /**
     * The request options shared by defaults, models.* and presets.*. All default to null (not set).
     */
    private function addOptionsNode(ArrayNodeDefinition $node): void
    {
        /** @var NodeBuilder $options */
        $options = $node->children();

        $options
            ->scalarNode('model')->defaultNull()->end()
            ->integerNode('max_tokens')->min(1)->defaultNull()->end()
            ->enumNode('thinking')
                ->info('adaptive | disabled | between_tools | omit (send nothing). "disabled" is adjusted for models that reject it.')
                ->values(['adaptive', 'disabled', 'between_tools', 'omit', null])
                ->defaultNull()
            ->end()
            ->enumNode('thinking_display')
                ->info('Only applies when thinking is adaptive')
                ->values(['summarized', 'omitted', 'updates', null])
                ->defaultNull()
            ->end()
            ->enumNode('effort')->values(['low', 'medium', 'high', 'xhigh', 'max', null])->defaultNull()->end()
            ->variableNode('fallbacks')
                ->info('"default" for server-chosen fallbacks, a list of model IDs, or false to switch off. Beta header added automatically.')
                ->defaultNull()
                ->validate()
                    ->ifTrue(fn ($value) => !(null === $value || false === $value || \is_string($value) || (\is_array($value) && array_is_list($value))))
                    ->thenInvalid('fallbacks must be "default", a list of model IDs, or false.')
                ->end()
            ->end()
            ->enumNode('speed')->info('"fast" for fast mode. Beta header added automatically.')->values(['fast', 'standard', null])->defaultNull()->end()
            ->floatNode('temperature')->defaultNull()->end()
            ->floatNode('top_p')->defaultNull()->end()
            ->integerNode('top_k')->defaultNull()->end()
            ->variableNode('stop_sequences')->info('List of strings')->defaultNull()->end()
            ->scalarNode('service_tier')->defaultNull()->end()
            ->scalarNode('inference_geo')->defaultNull()->end()
            ->variableNode('betas')->info('Extra beta headers. Accumulate across layers.')->defaultNull()->end()
        ->end();
    }

    private function withoutNulls(array $options): array
    {
        return array_filter($options, fn ($value) => null !== $value);
    }
}
