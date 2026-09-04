<?php

/**
 * @file
 * @brief Applies ADR-009 strong-evidence rules without performing persistence.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Pure fail-closed planner for automatic Kimai paid-state reconciliation.
 *
 * @details
 * The planner recognizes only the explicitly supported successful card-payment
 * states. Unknown or incomplete Stripe states remain non-mutating, while exact
 * identity/financial contradictions and anomalous object relationships are
 * surfaced for operator attention.
 */
final class ReconciliationPlanner
{
    /**
     * @param bool $kimaiPaid Whether Kimai already records the invoice as paid.
     * @param InvoiceMetadata $expected Exact Kimai identity and invoice money.
     * @param list<ReconciliationCandidate> $candidates Connector-managed Stripe evidence candidates.
     */
    public function plan(bool $kimaiPaid, InvoiceMetadata $expected, array $candidates): ReconciliationPlan
    {
        $logicalCandidates = [];

        foreach ($candidates as $candidate) {
            if ($this->sameLogicalInvoice($expected, $candidate->paymentLinkMetadata())) {
                $logicalCandidates[] = $candidate;
            }
        }

        if ($logicalCandidates === []) {
            return ReconciliationPlan::noChange('No Stripe Payment Link matches the exact Kimai invoice identity.');
        }

        foreach ($logicalCandidates as $candidate) {
            if (!$this->sameMoney($expected, $candidate->paymentLinkMetadata())) {
                return ReconciliationPlan::conflict(
                    'A Payment Link for the same Kimai invoice has conflicting amount or currency.',
                    $candidate,
                );
            }
        }

        if (count($logicalCandidates) > 1) {
            return ReconciliationPlan::conflict(
                'Multiple Payment Links match the same exact Kimai invoice identity.',
                $logicalCandidates[0],
            );
        }

        $candidate = $logicalCandidates[0];

        if (!$this->childMetadataMatches($expected, $candidate)) {
            return ReconciliationPlan::manualReview(
                'Stripe child-object metadata is inconsistent with the Payment Link identity.',
                $candidate,
            );
        }

        if ($this->hasFinancialConflict($expected, $candidate)) {
            return ReconciliationPlan::conflict(
                'Stripe payment evidence conflicts with the exact Kimai invoice amount or currency.',
                $candidate,
            );
        }

        if (!$this->relationshipsAreConsistent($candidate)) {
            return ReconciliationPlan::manualReview(
                'Stripe object relationships are incomplete or contradictory for automatic reconciliation.',
                $candidate,
            );
        }

        if ($this->hasRefundOrDispute($candidate)) {
            return ReconciliationPlan::manualReview(
                'Stripe reports a refund or dispute that requires accounting review.',
                $candidate,
            );
        }

        if ($kimaiPaid) {
            return ReconciliationPlan::noChange('Kimai already records the invoice as paid.', $candidate);
        }

        if (!$this->hasCompleteSuccessfulEvidence($expected, $candidate)) {
            return ReconciliationPlan::noChange(
                'Stripe evidence does not yet prove a complete successful captured payment.',
                $candidate,
            );
        }

        return ReconciliationPlan::markPaid($candidate);
    }

    private function sameLogicalInvoice(InvoiceMetadata $expected, InvoiceMetadata $actual): bool
    {
        return $expected->instanceId()->value() === $actual->instanceId()->value()
            && $expected->invoiceNumber() === $actual->invoiceNumber();
    }

    private function sameMoney(InvoiceMetadata $expected, InvoiceMetadata $actual): bool
    {
        return $expected->money()->minorUnits() === $actual->money()->minorUnits()
            && $expected->money()->currency()->value() === $actual->money()->currency()->value();
    }

    private function childMetadataMatches(InvoiceMetadata $expected, ReconciliationCandidate $candidate): bool
    {
        $session = $candidate->checkoutSession();
        $intent = $candidate->paymentIntent();

        if ($session !== null && $session->metadata()->toStripeMetadata() !== $expected->toStripeMetadata()) {
            return false;
        }

        if ($intent !== null && $intent->metadata()->toStripeMetadata() !== $expected->toStripeMetadata()) {
            return false;
        }

        return true;
    }

    private function hasFinancialConflict(InvoiceMetadata $expected, ReconciliationCandidate $candidate): bool
    {
        $expectedAmount = $expected->money()->minorUnits();
        $expectedCurrency = $expected->money()->currency()->value();
        $session = $candidate->checkoutSession();
        $intent = $candidate->paymentIntent();
        $charge = $candidate->charge();

        if (
            $session !== null
            && ($session->amountTotal() !== $expectedAmount || $session->currency() !== $expectedCurrency)
        ) {
            return true;
        }

        if (
            $intent !== null
            && ($intent->amount() !== $expectedAmount || $intent->currency() !== $expectedCurrency)
        ) {
            return true;
        }

        if ($intent !== null && $intent->amountReceived() > 0 && $intent->amountReceived() !== $expectedAmount) {
            return true;
        }

        if ($charge !== null && ($charge->amount() !== $expectedAmount || $charge->currency() !== $expectedCurrency)) {
            return true;
        }

        return false;
    }

    private function relationshipsAreConsistent(ReconciliationCandidate $candidate): bool
    {
        $session = $candidate->checkoutSession();
        $intent = $candidate->paymentIntent();
        $charge = $candidate->charge();

        if ($session !== null && $session->paymentLinkId() !== $candidate->paymentLinkId()) {
            return false;
        }

        if ($intent !== null) {
            if ($session === null || $session->paymentIntentId() !== $intent->id()) {
                return false;
            }
        }

        if ($charge !== null) {
            if ($intent === null || $intent->latestChargeId() !== $charge->id()) {
                return false;
            }

            if ($charge->paymentIntentId() !== $intent->id()) {
                return false;
            }
        }

        return true;
    }

    private function hasRefundOrDispute(ReconciliationCandidate $candidate): bool
    {
        $charge = $candidate->charge();

        return $charge !== null
            && ($charge->refunded() || $charge->disputed() || $charge->amountRefunded() > 0);
    }

    private function hasCompleteSuccessfulEvidence(
        InvoiceMetadata $expected,
        ReconciliationCandidate $candidate,
    ): bool {
        $session = $candidate->checkoutSession();
        $intent = $candidate->paymentIntent();
        $charge = $candidate->charge();

        if ($session === null || $intent === null || $charge === null) {
            return false;
        }

        return $session->status() === 'complete'
            && $session->paymentStatus() === 'paid'
            && $intent->status() === 'succeeded'
            && $intent->amountReceived() === $expected->money()->minorUnits()
            && $charge->paid()
            && $charge->captured()
            && !$charge->refunded()
            && !$charge->disputed()
            && $charge->amountRefunded() === 0;
    }
}
