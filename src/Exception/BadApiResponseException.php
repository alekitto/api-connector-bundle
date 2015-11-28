<?php

namespace Kcs\ApiConnectorBundle\Exception;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BadApiResponseException extends HttpException
{
    private $response;

    /**
     * @inheritDoc
     */
    public function __construct(ResponseInterface $response, $statusCode, $message = null, \Exception $previous = null, array $headers = array(), $code = 0)
    {
        $this->response = $response;

        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }
}
