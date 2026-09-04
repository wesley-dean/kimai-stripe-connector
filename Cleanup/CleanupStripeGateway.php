<?php

/**
 * @file
 * @brief Defines the narrowly scoped Stripe mutation boundary used by cleanup.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;

/**
 * @brief Stripe boundary allowed to deactivate one already-proven orphan Payment Link.
 */
interface CleanupStripeGateway
{
    /**
     * @return bool True when this call changed the Payment Link from active to inactive.
     *
     * @throws CleanupGatewayException When current Stripe state cannot safely authorize or confirm deactivation.
     */
    public function deactivatePaymentLink(ManagedPaymentLinkEvidence $expected): bool;
}
