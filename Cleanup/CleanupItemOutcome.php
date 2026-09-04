<?php

/**
 * @file
 * @brief Represents one operator-visible cleanup batch outcome.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Immutable result for one logical invoice cleanup group.
 */
final readonly class CleanupItemOutcome
{
    public function __construct(
        private string $invoiceNumber,
        private ?string $paymentLinkId,
        private OperatorResult $result,
        private bool $wouldChange,
        private bool $changed,
        private string $reason,
    ) {
    }

    public function invoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function paymentLinkId(): ?string
    {
        return $this->paymentLinkId;
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }

    public function wouldChange(): bool
    {
        return $this->wouldChange;
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
