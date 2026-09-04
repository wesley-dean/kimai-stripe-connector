<?php

/**
 * @file
 * @brief Describes the conservative result of reconciliation planning.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Immutable operator result plus whether Kimai should be marked paid.
 */
final readonly class ReconciliationPlan
{
    private function __construct(
        private OperatorResult $result,
        private bool $markPaid,
        private string $reason,
        private ?ReconciliationCandidate $candidate,
    ) {
    }

    public static function markPaid(ReconciliationCandidate $candidate): self
    {
        return new self(
            OperatorResult::SUCCESS,
            true,
            'Exact mutually consistent successful Stripe payment evidence was proven.',
            $candidate,
        );
    }

    public static function noChange(string $reason, ?ReconciliationCandidate $candidate = null): self
    {
        return new self(OperatorResult::NO_CHANGE, false, $reason, $candidate);
    }

    public static function conflict(string $reason, ReconciliationCandidate $candidate): self
    {
        return new self(OperatorResult::CONFLICT, false, $reason, $candidate);
    }

    public static function manualReview(string $reason, ?ReconciliationCandidate $candidate = null): self
    {
        return new self(OperatorResult::MANUAL_REVIEW_REQUIRED, false, $reason, $candidate);
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }

    public function shouldMarkPaid(): bool
    {
        return $this->markPaid;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function candidate(): ?ReconciliationCandidate
    {
        return $this->candidate;
    }
}
