<?php

namespace Kcs\ApiConnectorBundle\EventListener;

use Kcs\ApiConnectorBundle\Authentication\AuthenticatorInterface;
use Kcs\ApiConnectorBundle\Event\PreRequestEvent;
use Kcs\ApiConnectorBundle\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuthenticationListener implements EventSubscriberInterface
{
    private $authenticators = [];

    public function onPreRequest(PreRequestEvent $event)
    {
        $request = $event->getRequest();
        $authenticator = $event->getOption('authenticator', null);

        if ($event->getOption('anonymous', false) || null === $authenticator) {
            return;
        }

        $request = $authenticator->handle($request, null);
        $event->setRequest($request);
    }

    public function onResponse(ResponseEvent $event)
    {
        $request = $event->getRequest();

        if ($event->getOption('anonymous', false)) {
            return;
        }

        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($event->getResponse())) {
                $naRequest = $authenticator->handle($event->getRequest(), $event->getResponse());
                if ($naRequest !== $request) {
                    $event->setNextAttemptRequest($naRequest);
                    break;
                }
            }
        }
    }

    public function addAuthenticator(AuthenticatorInterface $authenticator)
    {
        $this->authenticators[] = $authenticator;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents()
    {
        return [
            'kcs.api.pre_request' => ['onPreRequest', -30],
            'kcs.api.response' => ['onResponse', -30]
        ];
    }
}
