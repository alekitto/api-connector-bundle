<?php

namespace Kcs\ApiConnectorBundle;

use Kcs\ApiConnectorBundle\DependencyInjection\Compiler\AddAuthenticatorsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class KcsApiConnectorBundle extends Bundle
{
    /**
     * @inheritDoc
     */
    public function build(ContainerBuilder $container)
    {
        $container
            ->addCompilerPass(new AddAuthenticatorsPass())
        ;
    }
}
