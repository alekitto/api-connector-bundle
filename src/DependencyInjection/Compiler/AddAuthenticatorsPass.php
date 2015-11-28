<?php

namespace Kcs\ApiConnectorBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AddAuthenticatorsPass implements CompilerPassInterface
{
    /**
     * @inheritDoc
     */
    public function process(ContainerBuilder $container)
    {
        $authListener = $container->getDefinition('kcs.api_connector.authentication_listener');
        foreach ($container->findTaggedServiceIds('kcs.api.authenticator') as $serviceId => $tags) {
            $authListener->addMethodCall('addAuthenticator', [new Reference($serviceId)]);
        }
    }
}
