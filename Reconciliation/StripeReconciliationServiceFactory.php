<?php

/**
 * @file
 * @brief Creates reconciliation services backed by Stripe and Kimai production adapters.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;

/**
 * @brief Production reconciliation service factory.
 */
final readonly class StripeReconciliationServiceFactory implements ReconciliationServiceFactory
{
    public function __construct(
        private StripeClientFactory $clientFactory,
        private ReconciliationInvoiceStore $invoiceStore,
    ) {
    }

    public function create(StripeCredential $credential): ReconciliationService
    {
        return new ReconciliationService(
            new StripeReconciliationGateway($this->clientFactory->create($credential)),
            $this->invoiceStore,
            new ReconciliationPlanner(),
        );
    }
}
