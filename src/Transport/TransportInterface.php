<?php

namespace Kcs\ApiConnectorBundle\Transport;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface TransportInterface
{
    /**
     * @param RequestInterface $request
     * @return PromiseInterface
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
