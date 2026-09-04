<?php

/**
 * @file
 * @brief Represents Charge evidence used by conservative reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;

/**
 * @brief Immutable subset of Charge state required by ADR-009.
 */
final readonly class ChargeEvidence
{
    /**
     * @throws InvalidArgumentException When identifiers, amount, currency, or timestamp are malformed.
     */
    public function __construct(
        private string $id,
        private bool $paid,
        private bool $captured,
        private bool $refunded,
        private bool $disputed,
        private int $amount,
        private int $amountCaptured,
        private int $amountRefunded,
        private string $currency,
        private string $paymentIntentId,
        private ?int $createdAt = null,
    ) {
        foreach ([$id, $currency, $paymentIntentId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Charge evidence fields must not be blank.');
            }
        }

        if ($amount < 0 || $amountCaptured < 0 || $amountRefunded < 0) {
            throw new InvalidArgumentException('Charge amounts must not be negative.');
        }

        if ($createdAt !== null && $createdAt <= 0) {
            throw new InvalidArgumentException('Charge creation timestamp must be a positive Unix timestamp.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function paid(): bool
    {
        return $this->paid;
    }

    public function captured(): bool
    {
        return $this->captured;
    }

    public function refunded(): bool
    {
        return $this->refunded;
    }

    public function disputed(): bool
    {
        return $this->disputed;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function amountCaptured(): int
    {
        return $this->amountCaptured;
    }

    public function amountRefunded(): int
    {
        return $this->amountRefunded;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function paymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    /**
     * @brief Return Stripe's immutable Charge creation timestamp when supplied by the gateway.
     */
    public function createdAt(): ?int
    {
        return $this->createdAt;
    }
}
