<?php

/**
 * @file
 * @brief Defines the read-only Stripe evidence boundary for reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;

/**
 * @brief Read-only Stripe boundary used by reconciliation orchestration.
 */
interface ReconciliationStripeGateway
{
    /**
     * @return list<ManagedPaymentLinkEvidence>
     *
     * @throws ReconciliationGatewayException When Stripe cannot be read safely.
     */
    public function listManagedPaymentLinks(KimaiInstanceId $instanceId): array;

    /**
     * @return list<ReconciliationCandidate>
     *
     * @throws ReconciliationGatewayException When Stripe evidence cannot be read safely.
     */
    public function listCandidates(ManagedPaymentLinkEvidence $paymentLink): array;
}
