<?php

/**
 * @file
 * @brief Defines runtime construction of credential-bound reconciliation services.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;

/**
 * @brief Creates one reconciliation service for the configured Stripe credential.
 */
interface ReconciliationServiceFactory
{
    public function create(StripeCredential $credential): ReconciliationService;
}
