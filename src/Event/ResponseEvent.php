<?php
namespace Kcs\ApiConnectorBundle\Event;

use Kcs\ApiConnectorBundle\Manager\ApiRequestManager;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\Event;

class ResponseEvent extends Event
{
    /**
     * @var ApiRequestManager
     */
    private $manager;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @var RequestInterface
     */
    private $nextAttemptRequest;

    /**
     * @var array
     */
    private $options;

    /**
     * ResponseEvent constructor.
     * @param ApiRequestManager $manager
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array $options
     */
    public function __construct(ApiRequestManager $manager, RequestInterface $request, ResponseInterface $response, array $options = [])
    {
        $this->manager = $manager;
        $this->request = $request;
        $this->response = $response;
        $this->options = $options;

        $this->nextAttemptRequest = $request;
    }

    /**
     * @return ApiRequestManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @return RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    /**
     * @return RequestInterface
     */
    public function getNextAttemptRequest()
    {
        return $this->nextAttemptRequest;
    }

    /**
     * @param RequestInterface $nextAttemptRequest
     */
    public function setNextAttemptRequest(RequestInterface $nextAttemptRequest)
    {
        $this->nextAttemptRequest = $nextAttemptRequest;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function getOption($name, $default = null)
    {
        return isset($this->options[$name]) ? $this->options[$name] : $default;
    }

    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }
}
