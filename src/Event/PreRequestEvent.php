<?php

namespace Kcs\ApiConnectorBundle\Event;

use Kcs\ApiConnectorBundle\Manager\ApiRequestManager;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\EventDispatcher\Event;

class PreRequestEvent extends Event
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
     * @var array
     */
    private $options;

    public function __construct(ApiRequestManager $manager, RequestInterface $request, array $options = [])
    {
        $this->manager = $manager;
        $this->request = $request;
        $this->options = $options;
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
     * @param RequestInterface $request
     */
    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;
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
