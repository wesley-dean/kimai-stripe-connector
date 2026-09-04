<?php

/**
 * @file
 * @brief Applies ADR-010 cleanup eligibility rules without mutating Stripe.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPlanner;

/**
 * @brief Pure fail-closed planner for stale unpaid orphan deactivation.
 *
 * @details
 * Cleanup is deliberately stricter than reconciliation's lack-of-success result.
 * A Payment Link is eligible only when there is no Kimai invoice, exactly one
 * matching managed link, the link is old and active, and every observed Stripe
 * attempt is conclusively unpaid.  In-progress, unsupported, contradictory, or
 * financially meaningful evidence is retained.
 */
final class CleanupPlanner
{
    private const SECONDS_PER_DAY = 86_400;

    /**
     * @param list<ManagedPaymentLinkEvidence> $paymentLinks Current-instance links for one invoice number.
     * @param list<ReconciliationCandidate> $candidates Stripe child evidence for those links.
     */
    public function plan(
        ?ReconciliationInvoice $invoice,
        array $paymentLinks,
        array $candidates,
        int $now,
        int $graceDays,
    ): CleanupPlan {
        if ($invoice !== null) {
            return CleanupPlan::noChange('A matching Kimai invoice exists; its Payment Link must be retained.');
        }

        if ($paymentLinks === []) {
            return CleanupPlan::internalError('Cleanup planning received no managed Payment Link evidence.');
        }

        if ($now <= 0 || $graceDays <= 0) {
            return CleanupPlan::internalError('Cleanup planning received an invalid clock or grace period.');
        }

        $expected = $paymentLinks[0]->metadata();
        $reconciliationPlan = (new ReconciliationPlanner())->plan(false, $expected, $candidates);

        if ($reconciliationPlan->shouldMarkPaid()) {
            return CleanupPlan::manualReview(
                'Successful Stripe payment evidence has no matching Kimai invoice; retain the paid orphan.',
            );
        }

        if ($reconciliationPlan->result() === OperatorResult::CONFLICT) {
            return CleanupPlan::conflict($reconciliationPlan->reason());
        }

        if ($reconciliationPlan->result() === OperatorResult::MANUAL_REVIEW_REQUIRED) {
            return CleanupPlan::manualReview($reconciliationPlan->reason());
        }

        if (count($paymentLinks) !== 1) {
            return CleanupPlan::conflict(
                'Multiple connector-managed Payment Links represent the same Kimai invoice number.',
            );
        }

        foreach ($candidates as $candidate) {
            if (!$this->isConclusiveUnpaidEvidence($expected, $candidate)) {
                return CleanupPlan::manualReview(
                    'Stripe payment state is not conclusively unpaid enough for automatic orphan cleanup.',
                );
            }
        }

        $paymentLink = $paymentLinks[0];
        $createdAt = $paymentLink->createdAt();
        if ($createdAt === null) {
            return CleanupPlan::manualReview(
                'Stripe Payment Link creation time is unavailable; cleanup age cannot be proven.',
            );
        }

        if ($createdAt > $now) {
            return CleanupPlan::manualReview(
                'Stripe Payment Link creation time is in the future relative to the cleanup clock.',
            );
        }

        $active = $paymentLink->active();
        if ($active === null) {
            return CleanupPlan::manualReview(
                'Stripe Payment Link active state is unavailable; deactivation state cannot be proven.',
            );
        }

        if (!$active) {
            return CleanupPlan::noChange('The proven unpaid orphan Payment Link is already inactive.');
        }

        $minimumAge = $graceDays * self::SECONDS_PER_DAY;
        if (($now - $createdAt) < $minimumAge) {
            return CleanupPlan::noChange('The proven unpaid orphan is still inside the configured cleanup grace period.');
        }

        return CleanupPlan::deactivate(
            'The Payment Link is a proven stale unpaid orphan and is eligible for deactivation.',
        );
    }

    private function isConclusiveUnpaidEvidence(
        InvoiceMetadata $expected,
        ReconciliationCandidate $candidate,
    ): bool {
        if ($candidate->paymentLinkMetadata()->toStripeMetadata() !== $expected->toStripeMetadata()) {
            return false;
        }

        $session = $candidate->checkoutSession();
        $intent = $candidate->paymentIntent();
        $charge = $candidate->charge();

        if ($session === null) {
            return $intent === null && $charge === null;
        }

        if ($session->metadata()->toStripeMetadata() !== $expected->toStripeMetadata()) {
            return false;
        }

        if ($session->paymentLinkId() !== $candidate->paymentLinkId()) {
            return false;
        }

        if (
            $session->amountTotal() !== $expected->money()->minorUnits()
            || $session->currency() !== $expected->money()->currency()->value()
        ) {
            return false;
        }

        if ($session->status() !== 'expired' || $session->paymentStatus() !== 'unpaid') {
            return false;
        }

        if ($intent === null) {
            return $charge === null;
        }

        if ($intent->metadata()->toStripeMetadata() !== $expected->toStripeMetadata()) {
            return false;
        }

        if (
            $intent->amount() !== $expected->money()->minorUnits()
            || $intent->currency() !== $expected->money()->currency()->value()
            || $intent->amountReceived() !== 0
            || $intent->latestChargeId() !== null
            || $charge !== null
        ) {
            return false;
        }

        return in_array($intent->status(), ['canceled', 'requires_payment_method'], true);
    }
}
