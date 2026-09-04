<?php

/**
 * @file
 * @brief Creates cleanup services backed by Stripe and Kimai production adapters.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoiceStore;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\StripeReconciliationGateway;

/**
 * @brief Production cleanup service factory.
 */
final readonly class StripeCleanupServiceFactory implements CleanupServiceFactory
{
    public function __construct(
        private StripeClientFactory $clientFactory,
        private ReconciliationInvoiceStore $invoiceStore,
    ) {
    }

    public function create(StripeCredential $credential): CleanupService
    {
        $client = $this->clientFactory->create($credential);

        return new CleanupService(
            new StripeReconciliationGateway($client),
            $this->invoiceStore,
            new StripeCleanupGateway($client),
            new CleanupPlanner(),
        );
    }
}
