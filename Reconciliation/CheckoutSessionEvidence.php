<?php

/**
 * @file
 * @brief Represents Checkout Session evidence used by conservative reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable subset of Checkout Session state required by ADR-009.
 */
final readonly class CheckoutSessionEvidence
{
    /**
     * @throws InvalidArgumentException When identifiers, state, amount, or currency are malformed.
     */
    public function __construct(
        private string $id,
        private string $status,
        private string $paymentStatus,
        private int $amountTotal,
        private string $currency,
        private string $paymentLinkId,
        private ?string $paymentIntentId,
        private InvoiceMetadata $metadata,
    ) {
        foreach ([$id, $status, $paymentStatus, $currency, $paymentLinkId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Checkout Session evidence fields must not be blank.');
            }
        }

        if ($paymentIntentId !== null && trim($paymentIntentId) === '') {
            throw new InvalidArgumentException('Checkout Session PaymentIntent ID must be null or non-blank.');
        }

        if ($amountTotal < 0) {
            throw new InvalidArgumentException('Checkout Session total must not be negative.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function paymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function amountTotal(): int
    {
        return $this->amountTotal;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function paymentLinkId(): string
    {
        return $this->paymentLinkId;
    }

    public function paymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function metadata(): InvoiceMetadata
    {
        return $this->metadata;
    }
}
