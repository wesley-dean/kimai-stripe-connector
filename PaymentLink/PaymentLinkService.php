<?php

/**
 * @file
 * @brief Orchestrates safe Payment Link discovery, reuse, creation, and recovery.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Coordinates the Payment Link capability without assuming distributed atomicity.
 */
final class PaymentLinkService
{
    private PaymentLinkPlanner $planner;

    public function __construct(
        private readonly PaymentLinkGateway $gateway,
        ?PaymentLinkPlanner $planner = null,
    ) {
        $this->planner = $planner ?? new PaymentLinkPlanner();
    }

    /**
     * @brief Ensure one exact active Payment Link exists for a Kimai invoice.
     *
     * @details
     * Preview requests return before any Stripe read or write.  Normal execution
     * rediscoveries existing links before creation.  If a create request has an
     * uncertain commit outcome, the service performs one rediscovery pass and never
     * blindly issues a second create in the same attempt.
     */
    public function ensure(bool $preview, InvoiceMetadata $expected): PaymentLinkOutcome
    {
        if ($preview) {
            return PaymentLinkOutcome::preview();
        }

        try {
            $candidates = $this->gateway->listAll();
        } catch (PaymentLinkGatewayException) {
            return PaymentLinkOutcome::retryable('Unable to inspect Stripe Payment Links.');
        }

        $plan = $this->planner->plan(false, $expected, $candidates);
        $plannedOutcome = $this->outcomeForNonCreatePlan($plan);

        if ($plannedOutcome !== null) {
            return $plannedOutcome;
        }

        $request = PaymentLinkCreateRequest::fromInvoiceMetadata($expected);

        try {
            $created = $this->gateway->create($request);
        } catch (PaymentLinkGatewayException $exception) {
            if (!$exception->commitMayHaveOccurred()) {
                return PaymentLinkOutcome::retryable('Stripe Payment Link creation failed.');
            }

            return $this->recoverAfterUncertainCommit($expected);
        }

        $createdPlan = $this->planner->plan(false, $expected, [$created]);

        if ($createdPlan->action() !== PaymentLinkAction::REUSE || $createdPlan->candidate() === null) {
            return PaymentLinkOutcome::manualReview(
                'Stripe returned a created Payment Link that does not match the requested invoice contract.'
            );
        }

        return PaymentLinkOutcome::created($createdPlan->candidate());
    }

    private function recoverAfterUncertainCommit(InvoiceMetadata $expected): PaymentLinkOutcome
    {
        try {
            $candidates = $this->gateway->listAll();
        } catch (PaymentLinkGatewayException) {
            return PaymentLinkOutcome::retryable(
                'Stripe Payment Link creation outcome is uncertain and rediscovery failed.'
            );
        }

        $plan = $this->planner->plan(false, $expected, $candidates);
        $outcome = $this->outcomeForNonCreatePlan($plan);

        if ($outcome !== null) {
            return $outcome;
        }

        return PaymentLinkOutcome::retryable(
            'Stripe Payment Link creation outcome remains uncertain; no duplicate create was attempted.'
        );
    }

    private function outcomeForNonCreatePlan(PaymentLinkPlan $plan): ?PaymentLinkOutcome
    {
        return match ($plan->action()) {
            PaymentLinkAction::PREVIEW => PaymentLinkOutcome::preview(),
            PaymentLinkAction::REUSE => $plan->candidate() === null
                ? PaymentLinkOutcome::internalError('Payment Link reuse plan is missing its candidate.')
                : PaymentLinkOutcome::reused($plan->candidate()),
            PaymentLinkAction::CONFLICT => PaymentLinkOutcome::conflict($plan->reason()),
            PaymentLinkAction::MANUAL_REVIEW_REQUIRED => PaymentLinkOutcome::manualReview($plan->reason()),
            PaymentLinkAction::CREATE => null,
        };
    }
}
