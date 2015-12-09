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
        $options = $this->optionsResolver->resolve(array_merge([
            'authenticator' => $this->defaultAuthenticator,
            'base_uri' => $this->baseUri
        ], $options));

        $request = $this->filterRequest($request, $options);

        $event = new PreRequestEvent($this, $request, $options);
        $this->eventDispatcher->dispatch('kcs.api.pre_request', $event);
        $options = $event->getOptions();

        $request = $event->getRequest();
        $attempt = ++$options['retries'];

        $event = new ResponseEvent($this, $request, $this->transport->exec($request), $options);
        $this->eventDispatcher->dispatch('kcs.api.response', $event);
        $options = $event->getOptions();

        $response = $event->getResponse();
        if (
            ($naReq = $event->getNextAttemptRequest()) &&
            !$this->isResponseOK($response) && $attempt < 3
        ) {
            $opts = $options;
            if ($naReq !== $request) {
                $opts['retries'] = 0;
            }

            $response = $this->performRequest($naReq, $opts);
        }

        if ($options['exceptions'] && !$this->isResponseOK($response)) {
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
            $opts = $this->optionsResolver->resolve(array_merge([
                'authenticator' => $this->defaultAuthenticator,
                'base_uri' => $this->baseUri
            ], isset($options[$key]) ? $options[$key] : []));

            $request = $this->filterRequest($request, $opts);
            $event = new PreRequestEvent($this, $request, $opts);
            $this->eventDispatcher->dispatch('kcs.api.pre_request', $event);
            $request = $event->getRequest();

            $options[$key] = $event->getOptions();
            $options[$key]['retries']++;
        }

        $responses = $this->transport->execMultiple($requests);

        $nextAttemptRequests = [];
        foreach ($responses as $key => &$response) {
            $request = $requests[$key];

            $event = new ResponseEvent($this, $request, $response, $options[$key]);
            $this->eventDispatcher->dispatch('kcs.api.response', $event);
            $options[$key] = $event->getOptions();

            $attempt = $options[$key]['retries'];
            $response = $event->getResponse();
            if (
                ($naReq = $event->getNextAttemptRequest()) &&
                !$this->isResponseOK($response) && $attempt < 3
            ) {
                if ($naReq !== $request) {
                    $options[$key]['retries'] = 0;
                }

                $nextAttemptRequests[$key] = $naReq;
            }
        }

        if ($nextAttemptRequests) {
            $responses = $this->performMultiple($nextAttemptRequests, $options) + $responses;
        }

        return $responses;
    }

    /**
     * @param string $defaultAuthenticator
     */
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
            'exceptions'        => true
        ]);
        $resolver->setDefined('authenticator');
        $resolver->setDefined('base_uri');

        $resolver->setAllowedTypes('authenticator', AuthenticatorInterface::class);
        $resolver->setAllowedTypes('base_uri', UriInterface::class);
        $resolver->setAllowedTypes('anonymous', 'bool');
        $resolver->setAllowedTypes('exceptions', 'bool');
        $resolver->setAllowedTypes('retries', 'int');
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
}
