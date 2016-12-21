<?php

namespace Otg\Ean\Subscriber;

use GuzzleHttp\Command\Event\ProcessEvent;
use GuzzleHttp\Event\SubscriberInterface;
use Otg\Ean\EanErrorException;
use Oi\Util\Object;
use Oi\Util\String;

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
        
        $supplierErrorCode = null;
        if (Object::pick($eanError, 'ErrorAttributes.errorAttributesMap.entry.key') == 'SUPPLIER_ERROR_CODE') {
            $supplierErrorCode = Object::pick($eanError, 'ErrorAttributes.errorAttributesMap.entry.value');
        }

        $presentationMessage = Object::pick($eanError, 'presentationMessage');

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
            
            // Map category to data property
            Object::set($e, 'data.category', String::camelCase(strtolower($e->getCategory())));
            
            $code = 490;
            switch ($e->getHandling()) {
                case 'RECOVERABLE':
                    switch ($e->getCategory()) {
                        case 'DATA_VALIDATION':
                            $code = 400;

                            // These seem to map to unrecoverable.soldOut
                            if ($presentationMessage == 'We\'re sorry but we\'re unable to process your request due to an unknown error.' && in_array($supplierErrorCode, [279, 608])) {
                                $code = 490;
                                Object::set($e, 'data.category', 'soldOut');
                            }
                            break;
                        case 'PRICE_MISMATCH':
                            $code = 400;
                            Object::set($e, 'data.fields.price', 'Price has changed');
                            break;
                        case 'CREDITCARD':
                            $code = 491;
                            break;
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

            $logger = $event->getClient()->getEmitter()->listeners('self')[0][0];
            $logger->handleEvent($event, 'error', $logger::LOG_ERROR);
            
            throw $e;
        }
    }
}
