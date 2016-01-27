<?php

namespace Kcs\ApiConnectorBundle\Manager;

use GuzzleHttp\Promise as P;
use Kcs\ApiConnectorBundle\Authentication\AnonymousAuthenticator;
use Kcs\ApiConnectorBundle\Authentication\AuthenticatorInterface;
use Kcs\ApiConnectorBundle\Event\PreRequestEvent;
use Kcs\ApiConnectorBundle\Event\ResponseEvent;
use Kcs\ApiConnectorBundle\Exception\BadApiResponseException;
use Kcs\ApiConnectorBundle\Transport\PromisebleTransportInterface;
use Kcs\ApiConnectorBundle\Transport\TransportInterface;
use Kcs\ApiConnectorBundle\Uri\BaseUri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ApiRequestManager is responsible for sending requests
 * after the options resolution and processing the response
 * retrying to send the request if necessary
 *
 * @author Alessandro Chitolina <alekitto@gmail.com>
 */
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

    /**
     * @var P\TaskQueue
     */
    private $queue;

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

        $this->queue = new P\TaskQueue();
    }

    /**
     * Performs a synchronous request.
     * Always returns a ResponseInterface object or throws a
     * {@link BadApiResponseException} if the response is not OK (2xx)
     *
     * @param RequestInterface $request
     * @param array $options
     *
     * @throws BadApiResponseException
     *
     * @return ResponseInterface
     */
    public function performRequest(RequestInterface $request, array $options = [])
    {
        return $this->send($request, $options)->wait();
    }

    /**
     * Perform an asynchronous request (if possible) and return a promise
     * object (an object implementing then and reject methods)
     *
     * @param RequestInterface $request
     * @param array $options
     *
     * @return object|P\PromiseInterface
     */
    public function send(RequestInterface $request, array $options = [])
    {
        $request = $this->preRequest($request, $options);
        $promise = $this->transport->exec($request)
            ->then(function(ResponseInterface $response) use ($request, $options, &$promise) {
                $event = $this->responseEvent($request, $response, $options);
                $response = $event->getResponse();

                if (! $this->isResponseOK($response)) {
                    return P\rejection_for($event);
                }

                return $response;
            })
            ->then(null, function(ResponseEvent $event) use ($options) {
                // options is passed by reference. Use array_merge to ensure
                // opts is a copy and not a reference
                $opts = array_merge([], $options);
                $naReq = $this->getNextRequest($event, $opts);

                if ($naReq) {
                    $options['exceptions'] = false;
                    return $this->send($naReq, $opts);
                }

                $response = $event->getResponse();
                if ($options['exceptions']) {
                    throw new BadApiResponseException($response, $response->getStatusCode(), $response->getReasonPhrase());
                }

                return $response;
            });

        // Add in shutdown queue
        $this->queue->add(function () use ($promise) {
            $promise->wait(false);
        });

        return $promise;
    }

    /**
     * @param RequestInterface[] $requests
     * @param array $options
     *
     * @return array
     */
    public function performMultiple($requests, array $options = [])
    {
        $promises = [];
        foreach ($requests as $key => $request) {
            $promises[$key] = $this->send($request, isset($options[$key]) ? $options[$key] : []);
        }

        P\queue()->run();

        $responses = [];
        foreach ($promises as $key => $promise) {
            $responses[$key] = $promise->wait();
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

        if (method_exists($resolver, 'setDefined')) {
            $resolver->setDefined('authenticator');
            $resolver->setDefined('base_uri');

            $resolver->setAllowedTypes('authenticator', AuthenticatorInterface::class);
            $resolver->setAllowedTypes('base_uri', UriInterface::class);
            $resolver->setAllowedTypes('anonymous', 'bool');
            $resolver->setAllowedTypes('exceptions', 'bool');
            $resolver->setAllowedTypes('retries', 'int');
            $resolver->setAllowedTypes('max_retries', 'int');
        } else {
            /** @noinspection PhpUndefinedMethodInspection */
            $resolver->setOptional([
                'authenticator',
                'base_uri'
            ]);

            /** @noinspection PhpParamsInspection */
            $resolver->setAllowedTypes([
                'authenticator' => AuthenticatorInterface::class,
                'base_uri' => UriInterface::class,
                'anonymous' => 'bool',
                'exceptions' => 'bool',
                'retries' => 'int',
                'max_retries' => 'int'
            ]);
        }
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
}
