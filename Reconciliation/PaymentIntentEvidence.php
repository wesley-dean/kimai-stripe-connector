<?php

/**
 * @file
 * @brief Represents PaymentIntent evidence used by conservative reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable subset of PaymentIntent state required by ADR-009.
 */
final readonly class PaymentIntentEvidence
{
    /**
     * @throws InvalidArgumentException When identifiers, state, amount, or currency are malformed.
     */
    public function __construct(
        private string $id,
        private string $status,
        private int $amount,
        private int $amountReceived,
        private string $currency,
        private ?string $latestChargeId,
        private InvoiceMetadata $metadata,
    ) {
        foreach ([$id, $status, $currency] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('PaymentIntent evidence fields must not be blank.');
            }
        }

        if ($latestChargeId !== null && trim($latestChargeId) === '') {
            throw new InvalidArgumentException('PaymentIntent latest Charge ID must be null or non-blank.');
        }

        if ($amount < 0 || $amountReceived < 0) {
            throw new InvalidArgumentException('PaymentIntent amounts must not be negative.');
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

    public function amount(): int
    {
        return $this->amount;
    }

    public function amountReceived(): int
    {
        return $this->amountReceived;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function latestChargeId(): ?string
    {
        return $this->latestChargeId;
    }

    public function metadata(): InvoiceMetadata
    {
        return $this->metadata;
    }
}
