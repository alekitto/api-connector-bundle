<?php

namespace Kcs\ApiConnectorBundle\Authentication;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AnonymousAuthenticator implements AuthenticatorInterface
{
    /**
     * @inheritDoc
     */
    public function handle(RequestInterface $request, ResponseInterface $response = null)
    {
        return $request;
    }

    /**
     * @inheritDoc
     */
    public function supports(ResponseInterface $response)
    {
        return false;
    }
}
