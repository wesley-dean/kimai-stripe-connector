<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGateway;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGatewayException;

final class SubscriberGateway implements PaymentLinkGateway
{
    /**
     * @param list<PaymentLinkCandidate>|PaymentLinkGatewayException $listResult
     */
    public function __construct(private array | PaymentLinkGatewayException $listResult)
    {
    }

    public function listAll(): array
    {
        if ($this->listResult instanceof PaymentLinkGatewayException) {
            throw $this->listResult;
        }

        return $this->listResult;
    }

    public function create(PaymentLinkCreateRequest $request): PaymentLinkCandidate
    {
        throw new PaymentLinkGatewayException('Subscriber test does not create Payment Links.');
    }
}
