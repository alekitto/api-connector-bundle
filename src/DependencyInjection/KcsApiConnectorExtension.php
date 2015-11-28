<?php

namespace Kcs\ApiConnectorBundle\DependencyInjection;

use Kcs\ApiConnectorBundle\Authentication\AnonymousAuthenticator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class KcsApiConnectorExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $this->loadManagers($config['managers'], $container);
    }

    protected function loadManagers($config, ContainerBuilder $container)
    {
        $baseDefinition = $container->getDefinition('kcs.api_connector.request_manager.abstract');
        foreach ($config as $name => $managerConfig) {
            $definition = clone $baseDefinition;
            $definition->setAbstract(false);

            switch ($managerConfig['transport']) {
                case 'guzzle':
                    $definition->replaceArgument(0, new Reference('kcs.api_connector.guzzle_transport'));
                    break;

                case 'custom':
                    $service_name = 'kcs.api_connector.custom_transport.' . $name;
                    $transport_definition = new Definition($managerConfig['transport_class']);

                    $container->setDefinition($service_name, $transport_definition);
                    $definition->replaceArgument(0, new Reference($service_name));
            }

            $definition->replaceArgument(2, $managerConfig['base_url']);

            $auth = isset($managerConfig['authenticator']) ? $managerConfig['authenticator'] : AnonymousAuthenticator::class;
            if (!class_exists($auth)) {
                $auth = new Reference($auth);
            }
            $definition->addMethodCall('setDefaultAuthenticator', [$auth]);

            $container->setDefinition('kcs.api_connector.request_manager.' . $name, $definition);
        }
    }
}
