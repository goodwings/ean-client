<?php

namespace Otg\Ean\Subscriber;

use GuzzleHttp\Command\Event\ProcessEvent;
use GuzzleHttp\Event\SubscriberInterface;
use Otg\Ean\EanErrorException;

class EanError implements SubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public function getEvents()
    {
        return ['process' => ['onProcess', 'first']];
    }

    public function onProcess(ProcessEvent $event)
    {
        $response = $event->getResponse();
        if (!$response) {
            return;
        }

        try {
            $xml = $response->xml();
        } catch (\RuntimeException $e) {
            return;
        }

        $eanError = $xml->EanError ?: $xml->EanWsError;

        if ($eanError) {
            $e = new EanErrorException((string) $eanError->presentationMessage, $event->getTransaction());

            $e->setHandling((string) $eanError->handling);
            $e->setCategory((string) $eanError->category);
            $e->setVerboseMessage((string) $eanError->verboseMessage);
            $e->setItineraryId((string) $eanError->itineraryId);
            
            /*
             * 400 - Retry user input
             * 490 - Restart booking flow
             * 491 - Agent attention required
             */
            
            $code = 490;
            switch ($e->getHandling()) {
                case 'RECOVERABLE':
                    switch ($e->getCategory()) {
                        case 'DATA_VALIDATION':
                        $code = 400;
                    }
                    break;
                case 'UNKNOWN':
                case 'UNRECOVERABLE':
                    $code = 490;
                    break;
                case 'AGENT_ATTENTION':
                    $code = 491;
                    break;
            }
            
            $e->setCode($code);

            throw $e;
        }
    }
}
