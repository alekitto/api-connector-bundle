<?php

namespace Kcs\ApiConnectorBundle\DependencyInjection;

use Kcs\ApiConnectorBundle\Authentication\AnonymousAuthenticator;
use Kcs\ApiConnectorBundle\Authentication\AuthenticatorInterface;
use Kcs\ApiConnectorBundle\Transport\TransportInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('kcs_api_connector');

        $supportedTransport = ['guzzle', 'custom'];

        $rootNode
            ->children()
                ->arrayNode('managers')
                ->useAttributeAsKey('id')
                ->validate()
                    ->ifTrue(function($v) { return (isset($v['transport']) && $v['transport'] === 'custom' && !isset($v['transport_class'])); })
                    ->thenInvalid('transport_class is not defined')
                ->end()
                ->prototype('array')
                ->children()
                    ->scalarNode('transport')
                    ->defaultValue($supportedTransport[0])
                        ->validate()
                            ->ifNotInArray($supportedTransport)
                            ->thenInvalid('The transport %s is not a supported transport. Use one of' . json_encode($supportedTransport))
                        ->end()
                    ->end()
                    ->scalarNode('transport_class')
                        ->validate()
                            ->ifTrue(function($v) { return !class_exists($v); })
                            ->thenInvalid('The class %s does not exist')
                            ->ifTrue(function($v) { return !is_subclass_of($v, TransportInterface::class); })
                            ->thenInvalid('%s does not implement TransportInterface')
                        ->end()
                    ->end()
                    ->scalarNode('base_url')->end()
                    ->scalarNode('authenticator')->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
