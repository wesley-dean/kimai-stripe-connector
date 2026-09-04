<?php

/**
 * @file
 * @brief Creates Payment Link services backed by the project-configured Stripe SDK client.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;

/**
 * @brief Production implementation of the Payment Link service factory.
 */
final readonly class StripePaymentLinkServiceFactory implements PaymentLinkServiceFactory
{
    public function __construct(private StripeClientFactory $clientFactory)
    {
    }

    public function create(StripeCredential $credential): PaymentLinkService
    {
        return new PaymentLinkService(
            StripePaymentLinkGateway::fromClient($this->clientFactory->create($credential)),
        );
    }
}
