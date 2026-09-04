<?php

/**
 * @file
 * @brief Represents a pure ADR-010 cleanup decision.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Immutable cleanup result separating evidence decisions from Stripe mutation.
 */
final readonly class CleanupPlan
{
    private function __construct(
        private OperatorResult $result,
        private bool $shouldDeactivate,
        private string $reason,
    ) {
    }

    public static function deactivate(string $reason): self
    {
        return new self(OperatorResult::SUCCESS, true, $reason);
    }

    public static function noChange(string $reason): self
    {
        return new self(OperatorResult::NO_CHANGE, false, $reason);
    }

    public static function conflict(string $reason): self
    {
        return new self(OperatorResult::CONFLICT, false, $reason);
    }

    public static function manualReview(string $reason): self
    {
        return new self(OperatorResult::MANUAL_REVIEW_REQUIRED, false, $reason);
    }

    public static function internalError(string $reason): self
    {
        return new self(OperatorResult::INTERNAL_ERROR, false, $reason);
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }

    public function shouldDeactivate(): bool
    {
        return $this->shouldDeactivate;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
