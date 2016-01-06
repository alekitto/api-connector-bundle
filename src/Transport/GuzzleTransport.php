<?php

namespace Kcs\ApiConnectorBundle\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\RequestInterface;

class GuzzleTransport implements TransportInterface
{
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
        return $this->client->send($request);
    }

    /**
     * @inheritDoc
     */
    public function execMultiple($requests)
    {
        ksort($requests);

        return array_combine(
            array_keys($requests),
            Pool::batch($this->client, $requests, ['concurrency' => 2])
        );
    }
}
