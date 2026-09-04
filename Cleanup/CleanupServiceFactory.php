<?php

/**
 * @file
 * @brief Defines production construction for cleanup orchestration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;

/**
 * @brief Builds cleanup services from a validated Stripe credential.
 */
interface CleanupServiceFactory
{
    public function create(StripeCredential $credential): CleanupService;
}
