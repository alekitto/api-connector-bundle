<?php

namespace Kcs\ApiConnectorBundle\Manager;

use Kcs\ApiConnectorBundle\Authentication\AnonymousAuthenticator;
use Kcs\ApiConnectorBundle\Authentication\AuthenticatorInterface;
use Kcs\ApiConnectorBundle\Event\PreRequestEvent;
use Kcs\ApiConnectorBundle\Event\ResponseEvent;
use Kcs\ApiConnectorBundle\Exception\BadApiResponseException;
use Kcs\ApiConnectorBundle\Transport\TransportInterface;
use Kcs\ApiConnectorBundle\Uri\BaseUri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ApiRequestManager
{
    /**
     * @var array
     */
    protected $defaultOptions;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var UriInterface
     */
    private $baseUri;

    /**
     * @var OptionsResolver
     */
    private $optionsResolver;

    /**
     * @var string
     */
    private $defaultAuthenticator;

    public function __construct(
        TransportInterface $transport,
        EventDispatcherInterface $eventDispatcher,
        $base_uri
    )
    {
        $this->transport = $transport;
        $this->eventDispatcher = $eventDispatcher;
        $this->baseUri = $this->createBaseUri($base_uri);
        $this->defaultAuthenticator = new AnonymousAuthenticator();

        $this->optionsResolver = new OptionsResolver();
        $this->configureOptions($this->optionsResolver);
    }

    public function performRequest(RequestInterface $request, array $options = [])
    {
        $request = $this->preRequest($request, $options);
        $response = $this->onResponse($request, $this->transport->exec($request), $options);

        if ($options['exceptions'] && ! $this->isResponseOK($response)) {
            throw new BadApiResponseException($response, $response->getStatusCode(), $response->getReasonPhrase());
        }

        return $response;
    }

    /**
     * @param RequestInterface[] $requests
     * @param array $options
     *
     * @return array
     */
    public function performMultiple($requests, array $options = [])
    {
        foreach ($requests as $key => &$request) {
            if (!isset($options[$key])) {
                $options[$key] = [];
            }

            $request = $this->preRequest($request, $options[$key]);
        }

        $responses = $this->transport->execMultiple($requests);

        $nextAttemptRequests = [];
        foreach ($responses as $key => &$response) {
            $request = $requests[$key];

            $event = $this->responseEvent($request, $response, $options[$key]);
            $response = $event->getResponse();

            if (! $this->isResponseOK($response)) {
                $naReq = $this->getNextRequest($event, $options[$key]);
                $nextAttemptRequests[$key] = $naReq;

                if (! $naReq && $options[$key]['exceptions']) {
                    throw new BadApiResponseException($response, $response->getStatusCode(), $response->getReasonPhrase());
                }
            }
        }

        $nextAttemptRequests = array_filter($nextAttemptRequests);
        if ($nextAttemptRequests) {
            foreach ($this->performMultiple($nextAttemptRequests, $options) as $key => $response) {
                $responses[$key] = $response;
            };
        }

        return $responses;
    }

    public function setDefaultAuthenticator($defaultAuthenticator)
    {
        if (is_string($defaultAuthenticator)) {
            $defaultAuthenticator = new $defaultAuthenticator;
        }

        $this->defaultAuthenticator = $defaultAuthenticator;
    }

    protected function createBaseUri($base_uri)
    {
        return new BaseUri($base_uri);
    }

    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'anonymous'         => false,
            'retries'           => 0,
            'tag'               => null,
            'exceptions'        => true,
            'max_retries'       => 3
        ]);
        $resolver->setDefined('authenticator');
        $resolver->setDefined('base_uri');

        $resolver->setAllowedTypes('authenticator', AuthenticatorInterface::class);
        $resolver->setAllowedTypes('base_uri', UriInterface::class);
        $resolver->setAllowedTypes('anonymous', 'bool');
        $resolver->setAllowedTypes('exceptions', 'bool');
        $resolver->setAllowedTypes('retries', 'int');
        $resolver->setAllowedTypes('max_retries', 'int');
    }

    private function filterRequest(RequestInterface $request, array $options)
    {
        if (($base_uri = $options['base_uri'])) {
            $uri = $request->getUri();
            $resolved = BaseUri::resolve($base_uri, $uri);

            if ((string)$uri !== (string)$resolved) {
                $request = $request->withUri($resolved);
            }
        }

        return $request;
    }

    private function isResponseOK(ResponseInterface $response)
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    private function preRequest(RequestInterface $request, array &$options = null)
    {
        $options = $this->optionsResolver->resolve(array_merge([
            'authenticator' => $this->defaultAuthenticator,
            'base_uri' => $this->baseUri
        ], $options ?: []));

        $request = $this->filterRequest($request, $options);

        $event = new PreRequestEvent($this, $request, $options);
        $this->eventDispatcher->dispatch('kcs.api.pre_request', $event);
        $options = $event->getOptions();

        $request = $event->getRequest();
        ++$options['retries'];
        return $request;
    }

    private function getNextRequest(ResponseEvent $event, array &$options)
    {
        $req = $event->getNextAttemptRequest();
        if ($options['retries'] >= $options['max_retries']) {
            return null;
        }

        if ($event->getRequest() !== $req) {
            $options['retries'] = 0;
        }

        return $req;
    }

    private function responseEvent(RequestInterface $request, ResponseInterface $response, array &$options)
    {
        $event = new ResponseEvent($this, $request, $response, $options);
        $this->eventDispatcher->dispatch('kcs.api.response', $event);
        $options = $event->getOptions();

        return $event;
    }

    private function onResponse(RequestInterface $request, ResponseInterface $response, array &$options)
    {
        $event = $this->responseEvent($request, $response, $options);
        $response = $event->getResponse();

        if (! $this->isResponseOK($response)) {
            // options is passed by reference. Use array_merge to ensure
            // opts is a copy and not a reference
            $opts = array_merge([], $options);
            $naReq = $this->getNextRequest($event, $opts);

            if ($naReq) {
                $options['exceptions'] = false;
                $response = $this->performRequest($naReq, $opts);
            }
        }

        return $response;
    }
}
