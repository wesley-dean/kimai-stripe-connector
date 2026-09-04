<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkService;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkServiceFactory;

final class StubPaymentLinkServiceFactory implements PaymentLinkServiceFactory
{
    public int $createCalls = 0;

    public function __construct(private readonly PaymentLinkService $service)
    {
    }

    public function create(StripeCredential $credential): PaymentLinkService
    {
        ++$this->createCalls;

        return $this->service;
    }
}
