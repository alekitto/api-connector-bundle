<?php

namespace Kcs\ApiConnectorBundle\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\RequestInterface;

class GuzzleTransport implements TransportInterface
{
    /**
     * @var Client
     */
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'exceptions'    => false,
            'verify'        => false
        ]);
    }

    /**
     * @inheritDoc
     */
    public function exec(RequestInterface $request)
    {
        return $this->client->sendAsync($request);
    }
}
