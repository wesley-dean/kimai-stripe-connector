<?php

/**
 * @file
 * @brief Groups Stripe objects that may prove payment for one managed invoice.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Correlated Stripe evidence rooted at one connector-managed Payment Link.
 */
final readonly class ReconciliationCandidate
{
    /**
     * @throws InvalidArgumentException When the Payment Link identifier is blank.
     */
    public function __construct(
        private string $paymentLinkId,
        private InvoiceMetadata $paymentLinkMetadata,
        private ?CheckoutSessionEvidence $checkoutSession = null,
        private ?PaymentIntentEvidence $paymentIntent = null,
        private ?ChargeEvidence $charge = null,
    ) {
        if (trim($paymentLinkId) === '') {
            throw new InvalidArgumentException('Payment Link ID must not be blank.');
        }
    }

    public function paymentLinkId(): string
    {
        return $this->paymentLinkId;
    }

    public function paymentLinkMetadata(): InvoiceMetadata
    {
        return $this->paymentLinkMetadata;
    }

    public function checkoutSession(): ?CheckoutSessionEvidence
    {
        return $this->checkoutSession;
    }

    public function paymentIntent(): ?PaymentIntentEvidence
    {
        return $this->paymentIntent;
    }

    public function charge(): ?ChargeEvidence
    {
        return $this->charge;
    }
}
