<?php

namespace Kcs\ApiConnectorBundle\Tests\Manager;

use GuzzleHttp\Promise\FulfilledPromise;
use Kcs\ApiConnectorBundle\Event\PreRequestEvent;
use Kcs\ApiConnectorBundle\Event\ResponseEvent;
use Kcs\ApiConnectorBundle\Manager\ApiRequestManager;
use Kcs\ApiConnectorBundle\Transport\TransportInterface;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ApiRequestManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ApiRequestManager
     */
    private $manager;

    public function setUp()
    {
        $this->transport = $this->prophesize(TransportInterface::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
        $this->eventDispatcher->dispatch(Argument::cetera())->shouldBeCalled();

        $this->successResponse = $this->prophesize(ResponseInterface::class);
        $this->successResponse->getStatusCode()->willReturn(200);

        $this->manager = new ApiRequestManager($this->transport->reveal(), $this->eventDispatcher->reveal(), '');
    }

    public function testPerformsRequest()
    {
        /** @var RequestInterface $request */
        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request)->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $response = $this->manager->performRequest($request->reveal());

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testPerformRequestRetriesOnFailure()
    {
        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);

        $this->transport->exec($request->reveal())
            ->shouldBeCalledTimes(3)
            ->willReturn(
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($this->successResponse->reveal())
            );

        $this->manager->performRequest($request->reveal());
    }

    public function testPerformRequestRetriesCorrectTimesOnFailure()
    {
        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);

        $this->transport->exec($request->reveal())
            ->shouldBeCalledTimes(5)
            ->willReturn(
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($this->successResponse->reveal())
            );

        $this->manager->performRequest($request->reveal(), ['max_retries' => 5]);
    }

    /**
     * @expectedException \Kcs\ApiConnectorBundle\Exception\BadApiResponseException
     */
    public function testPerformRequestThrowsExceptionsByDefaultOnFailure()
    {
        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);
        $failureResponse->getReasonPhrase()->willReturn("Conflict");

        $this->transport->exec($request->reveal())
            ->willReturn(
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal())
            );

        $this->manager->performRequest($request->reveal());
    }

    public function testPerformRequestDoNotThrowsExceptionsIfExceptionOptionIsSetToFalse()
    {
        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);
        $failureResponse->getReasonPhrase()->willReturn("Conflict");

        $this->transport->exec($request->reveal())
            ->willReturn(
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal()),
                new FulfilledPromise($failureResponse->reveal())
            );

        $response = $this->manager->performRequest($request->reveal(), ['exceptions' => false]);
        $this->assertSame($failureResponse->reveal(), $response);
    }

    public function testPerformRequestDispatchPreRequestEvent()
    {
        $this->eventDispatcher->dispatch('kcs.api.pre_request', Argument::type(PreRequestEvent::class))
            ->shouldBeCalled();

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request->reveal())->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $this->manager->performRequest($request->reveal());
    }

    public function testRequestCanBeChangedInPreRequestEvent()
    {
        $secondRequest = $this->prophesize(RequestInterface::class);
        $secondRequest->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $secondRequest = $secondRequest->reveal();

        $this->eventDispatcher->dispatch('kcs.api.pre_request', Argument::type(PreRequestEvent::class))
            ->will(function($arguments) use ($secondRequest) {
                $event = $arguments[1];
                $event->setRequest($secondRequest);
            });

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($secondRequest)->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $this->manager->performRequest($request->reveal());
    }

    public function testPerformRequestDispatchResponseEvent()
    {
        $this->eventDispatcher->dispatch('kcs.api.response', Argument::type(ResponseEvent::class))
            ->shouldBeCalled();

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request->reveal())->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $this->manager->performRequest($request->reveal());
    }

    public function testResponseCanBeChangedInResponseEvent()
    {
        $alternateResponse = $this->prophesize(ResponseInterface::class);
        $alternateResponse->getStatusCode()->willReturn(200);
        $alternateResponse = $alternateResponse->reveal();

        $this->eventDispatcher->dispatch('kcs.api.response', Argument::type(ResponseEvent::class))
            ->will(function($arguments) use ($alternateResponse) {
                $event = $arguments[1];
                $event->setResponse($alternateResponse);
            });

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request)
            ->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $response = $this->manager->performRequest($request->reveal());
        $this->assertSame($alternateResponse, $response);
    }

    public function testRequestCanBeChangedInResponseEventForNextAttempt()
    {
        $successRequest = $this->prophesize(RequestInterface::class);
        $successRequest->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $successRequest = $successRequest->reveal();
        $this->eventDispatcher->dispatch('kcs.api.response', Argument::type(ResponseEvent::class))
            ->will(function($arguments) use ($successRequest) {
                $event = $arguments[1];
                $event->setNextAttemptRequest($successRequest);
            });

        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request)
            ->willReturn(new FulfilledPromise($failureResponse->reveal()));
        $this->transport->exec($successRequest)
            ->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $response = $this->manager->performRequest($request->reveal());
        $this->assertSame($this->successResponse->reveal(), $response);
    }

    public function testRequestChangedInResponseEventResetsRetriesCount()
    {
        $that = $this;
        $successRequest = $this->prophesize(RequestInterface::class);
        $successRequest->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $successRequest = $successRequest->reveal();
        $this->eventDispatcher->dispatch('kcs.api.response', Argument::type(ResponseEvent::class))
            ->will(function($arguments) use ($successRequest) {
                $event = $arguments[1];
                $event->setNextAttemptRequest($successRequest);
            });
        $this->eventDispatcher->dispatch('kcs.api.pre_request', Argument::type(PreRequestEvent::class))
            ->will(function($arguments) use ($that) {
                $event = $arguments[1];
                $opts = $event->getOptions();

                $that->assertEquals(0, $opts['retries']);
            });

        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request)
            ->willReturn(new FulfilledPromise($failureResponse->reveal()));
        $this->transport->exec($successRequest)
            ->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $response = $this->manager->performRequest($request->reveal());
        $this->assertSame($this->successResponse->reveal(), $response);
    }

    public function resolveBaseUriDataProvider()
    {
        return [
            ['http://api.example.com/endpoint_example', 'http://api.example.com/', '/endpoint_example'],
            ['http://no.example.org/exex', 'http://api.example.com', 'http://no.example.org/exex'],
            ['/test_no_host', '', '/test_no_host'],
            ['http://api.example.com/v2/coll/res', 'http://api.example.com/v2/', 'coll/res'],
            ['http://api.example.com/coll/res', 'http://api.example.com/other_coll', 'coll/res'],
            ['http://api.example.com/collection/resource', 'http://api.example.com', 'collection/resource'],
            ['http://user:passwd@api.example.com/coll/res', 'http://user:passwd@api.example.com/', '/coll/res'],
            ['http://user@api.example.com/test', 'http://user@api.example.com/', '/test'],
            ['http://api.example.com:8000/test', 'http://api.example.com:8000/', '/test'],
        ];
    }

    /**
     * @dataProvider resolveBaseUriDataProvider
     */
    public function testUriIsResolvedWithBaseUri($expected, $base_uri, $relative_uri)
    {
        $that = $this;
        $apiManager = new ApiRequestManager(
            $this->transport->reveal(),
            $this->eventDispatcher->reveal(),
            $base_uri
        );

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for($relative_uri));
        $request->withUri(Argument::type(UriInterface::class))
            ->will(function($arguments) use ($that, $expected) {
                $uri = (string)$arguments[0];
                $that->assertEquals($expected, $uri);

                return $this;
            });
        $request = $request->reveal();

        $this->transport->exec($request)
            ->shouldBeCalled()
            ->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $apiManager->performRequest($request);
    }

    public function testRequestChangedInResponseEventForNextAttemptRetainsOptions()
    {
        $that = $this;
        $successRequest = $this->prophesize(RequestInterface::class);
        $successRequest->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $successRequest = $successRequest->reveal();
        $this->eventDispatcher->dispatch('kcs.api.response', Argument::type(ResponseEvent::class))
            ->will(function($arguments) use ($successRequest, $that) {
                $event = $arguments[1];
                $event->setNextAttemptRequest($successRequest);

                $that->assertEquals('example_val', $event->getOption('tag'));
            });

        $failureResponse = $this->prophesize(ResponseInterface::class);
        $failureResponse->getStatusCode()->willReturn(409);

        $request = $this->prophesize(RequestInterface::class);
        $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
        $this->transport->exec($request)
            ->willReturn(new FulfilledPromise($failureResponse->reveal()));
        $this->transport->exec($successRequest)
            ->willReturn(new FulfilledPromise($this->successResponse->reveal()));

        $this->manager->performRequest($request->reveal(), ['tag' => 'example_val']);
    }

    public function testPerformMultipleShouldReturnResponsesInOrder()
    {
        $requests = [];
        for ($i = 0; $i < 5; $i++) {
            $request = $this->prophesize(RequestInterface::class);
            $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
            $requests["a_$i"] = $request->reveal();

            $successResponse = $this->prophesize(ResponseInterface::class);
            $successResponse->getStatusCode()->willReturn(200);

            $this->transport->exec($request->reveal())
                ->willReturn(new FulfilledPromise($successResponse->reveal()));
        }

        $resps = $this->manager->performMultiple($requests);
        $this->assertEquals(array_keys($requests), array_keys($resps));
    }

    public function testPerformMultipleShouldRetryOnlyFailedRequests()
    {
        $requests = [];
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $request = $this->prophesize(RequestInterface::class);
            $request->getUri()->willReturn(\GuzzleHttp\Psr7\uri_for(''));
            $requests["a_$i"] = $request->reveal();

            $response = $this->prophesize(ResponseInterface::class);
            $response->getStatusCode()->willReturn($i == 3 ? 400 : 200);
            $response->getReasonPhrase()->willReturn('STATUS');

            if (3 != $i) {
                $this->transport->exec($request->reveal())
                    ->willReturn(new FulfilledPromise($response->reveal()));
            } else {
                $this->transport->exec($request->reveal())
                    ->willReturn(
                        new FulfilledPromise($response->reveal()),
                        new FulfilledPromise($this->successResponse->reveal())
                    );
            }
        }

        $resps = $this->manager->performMultiple($requests);
        $this->assertEquals(array_keys($requests), array_keys($resps));
    }
}
