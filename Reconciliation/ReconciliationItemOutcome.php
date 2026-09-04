<?php

/**
 * @file
 * @brief Represents one invoice-level reconciliation result for operator reporting.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Immutable invoice-level outcome including planned and applied mutation state.
 */
final readonly class ReconciliationItemOutcome
{
    /**
     * @throws InvalidArgumentException When the invoice number or reason is blank.
     */
    public function __construct(
        private string $invoiceNumber,
        private OperatorResult $result,
        private bool $wouldChange,
        private bool $changed,
        private string $reason,
    ) {
        if (trim($invoiceNumber) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('Reconciliation outcome fields must not be blank.');
        }

        if ($changed && !$wouldChange) {
            throw new InvalidArgumentException('A reconciliation change must have been planned before it was applied.');
        }
    }

    public function invoiceNumber(): string
    {
        return $this->invoiceNumber;
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
