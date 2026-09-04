<?php

/**
 * @file
 * @brief Carries the operator-safe outcome of Payment Link orchestration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Result category plus optional safe customer-facing Payment Link URL.
 */
final readonly class PaymentLinkOutcome
{
    private function __construct(
        private OperatorResult $result,
        private ?string $url,
        private string $message,
    ) {
    }

    public static function preview(): self
    {
        return new self(OperatorResult::NO_CHANGE, null, 'Invoice preview performed no Stripe mutation.');
    }

    public static function reused(PaymentLinkCandidate $candidate): self
    {
        return new self(OperatorResult::NO_CHANGE, $candidate->url(), 'Reused existing Payment Link.');
    }

    public static function created(PaymentLinkCandidate $candidate): self
    {
        return new self(OperatorResult::SUCCESS, $candidate->url(), 'Created Payment Link.');
    }

    public static function retryable(string $message): self
    {
        return new self(OperatorResult::RETRYABLE, null, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(OperatorResult::CONFLICT, null, $message);
    }

    public static function manualReview(string $message): self
    {
        return new self(OperatorResult::MANUAL_REVIEW_REQUIRED, null, $message);
    }

    public static function internalError(string $message): self
    {
        return new self(OperatorResult::INTERNAL_ERROR, null, $message);
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function message(): string
    {
        return $this->message;
    }
}
