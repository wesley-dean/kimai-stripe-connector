<?php

/**
 * @file
 * @brief Carries a deterministic Payment Link planning decision.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

/**
 * @brief Immutable planning result produced before Stripe mutation.
 */
final readonly class PaymentLinkPlan
{
    private function __construct(
        private PaymentLinkAction $action,
        private ?PaymentLinkCandidate $candidate,
        private string $reason,
    ) {
    }

    public static function preview(): self
    {
        return new self(PaymentLinkAction::PREVIEW, null, 'Invoice preview must not mutate Stripe.');
    }

    public static function create(): self
    {
        return new self(PaymentLinkAction::CREATE, null, 'No exact compatible Payment Link exists.');
    }

    public static function reuse(PaymentLinkCandidate $candidate): self
    {
        return new self(PaymentLinkAction::REUSE, $candidate, 'Exactly one compatible active Payment Link exists.');
    }

    public static function conflict(string $reason): self
    {
        return new self(PaymentLinkAction::CONFLICT, null, $reason);
    }

    public static function manualReview(string $reason): self
    {
        return new self(PaymentLinkAction::MANUAL_REVIEW_REQUIRED, null, $reason);
    }

    public function action(): PaymentLinkAction
    {
        return $this->action;
    }

    public function candidate(): ?PaymentLinkCandidate
    {
        return $this->candidate;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
