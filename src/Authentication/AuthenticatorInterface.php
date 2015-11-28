<?php

namespace Kcs\ApiConnectorBundle\Authentication;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface AuthenticatorInterface
{
    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     *
     * @return RequestInterface
     */
    public function handle(RequestInterface $request, ResponseInterface $response = null);

    /**
     * @param ResponseInterface $response
     *
     * @return bool
     */
    public function supports(ResponseInterface $response);
}
