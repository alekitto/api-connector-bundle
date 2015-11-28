<?php

namespace Kcs\ApiConnectorBundle\Transport;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface TransportInterface
{
    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     *
     * @throws
     */
    public function exec(RequestInterface $request);

    /**
     * @param RequestInterface[] $requests
     * @return ResponseInterface[]
     *
     * @throws
     */
    public function execMultiple($requests);
}
