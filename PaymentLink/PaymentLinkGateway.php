<?php

/**
 * @file
 * @brief Defines the narrow Stripe Payment Link boundary used by orchestration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

/**
 * @brief Remote boundary required by the Payment Link capability.
 *
 * @details
 * The interface is intentionally specific to the connector's Payment Link
 * workflow.  It exists to make pagination, network failure, and uncertain-commit
 * behavior deterministic in tests without inventing a generalized payment API.
 */
interface PaymentLinkGateway
{
    /**
     * @return list<PaymentLinkCandidate>
     *
     * @throws PaymentLinkGatewayException When Stripe discovery fails.
     */
    public function listAll(): array;

    /**
     * @throws PaymentLinkGatewayException When the Stripe create request fails.
     */
    public function create(PaymentLinkCreateRequest $request): PaymentLinkCandidate;
}
