<?php

/**
 * @file
 * @brief Orchestrates ADR-010 orphan discovery, revalidation, and optional Stripe deactivation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoiceStore;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPersistenceException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationStripeGateway;

/**
 * @brief Conservative cleanup orchestration independent of Kimai framework classes.
 *
 * @details
 * Cleanup first proves that Kimai has no matching invoice.  Stripe child evidence
 * is then inspected and a pure plan is produced.  Before any destructive update,
 * Kimai and Stripe child evidence are queried again.  The mutation adapter performs
 * a final Payment Link retrieve/compare before setting active=false.
 */
final readonly class CleanupService
{
    public function __construct(
        private ReconciliationStripeGateway $stripeReader,
        private ReconciliationInvoiceStore $invoices,
        private CleanupStripeGateway $stripeWriter,
        private CleanupPlanner $planner,
    ) {
    }

    /**
     * @return list<CleanupItemOutcome>
     *
     * @throws ReconciliationGatewayException When initial managed-link discovery cannot begin safely.
     */
    public function cleanup(
        KimaiInstanceId $instanceId,
        int $graceDays,
        bool $dryRun = false,
        ?int $now = null,
    ): array {
        $now ??= time();
        $groups = $this->groupByInvoiceNumber($this->stripeReader->listManagedPaymentLinks($instanceId));
        ksort($groups, SORT_STRING);
        $outcomes = [];

        foreach ($groups as $invoiceNumber => $paymentLinks) {
            $paymentLinkId = count($paymentLinks) === 1 ? $paymentLinks[0]->id() : null;

            try {
                $invoice = $this->invoices->findByInvoiceNumber($instanceId, $invoiceNumber);
            } catch (ReconciliationPersistenceException $exception) {
                $outcomes[] = $this->outcomeFromPersistenceFailure(
                    $invoiceNumber,
                    $paymentLinkId,
                    $exception,
                );
                continue;
            }

            if ($invoice !== null) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    OperatorResult::NO_CHANGE,
                    false,
                    false,
                    'A matching Kimai invoice exists; cleanup retained the Payment Link.',
                );
                continue;
            }

            try {
                $candidates = $this->loadCandidates($paymentLinks);
            } catch (ReconciliationGatewayException $exception) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    $exception->result(),
                    false,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            $plan = $this->planner->plan(null, $paymentLinks, $candidates, $now, $graceDays);
            if (!$plan->shouldDeactivate()) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    $plan->result(),
                    false,
                    false,
                    $plan->reason(),
                );
                continue;
            }

            if ($dryRun) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    OperatorResult::SUCCESS,
                    true,
                    false,
                    'Dry run: the proven stale unpaid orphan Payment Link would be deactivated.',
                );
                continue;
            }

            try {
                $currentInvoice = $this->invoices->findByInvoiceNumber($instanceId, $invoiceNumber);
            } catch (ReconciliationPersistenceException $exception) {
                $outcomes[] = $this->outcomeFromPersistenceFailure(
                    $invoiceNumber,
                    $paymentLinkId,
                    $exception,
                    true,
                );
                continue;
            }

            if ($currentInvoice !== null) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    OperatorResult::NO_CHANGE,
                    false,
                    false,
                    'Kimai acquired a matching invoice before cleanup; the Payment Link was retained.',
                );
                continue;
            }

            try {
                $currentCandidates = $this->loadCandidates($paymentLinks);
            } catch (ReconciliationGatewayException $exception) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    $exception->result(),
                    true,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            $currentPlan = $this->planner->plan(null, $paymentLinks, $currentCandidates, $now, $graceDays);
            if (!$currentPlan->shouldDeactivate()) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    $currentPlan->result(),
                    false,
                    false,
                    'Cleanup revalidation retained the Payment Link: ' . $currentPlan->reason(),
                );
                continue;
            }

            try {
                $changed = $this->stripeWriter->deactivatePaymentLink($paymentLinks[0]);
            } catch (CleanupGatewayException $exception) {
                $outcomes[] = new CleanupItemOutcome(
                    $invoiceNumber,
                    $paymentLinkId,
                    $exception->result(),
                    true,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            $outcomes[] = new CleanupItemOutcome(
                $invoiceNumber,
                $paymentLinkId,
                $changed ? OperatorResult::SUCCESS : OperatorResult::NO_CHANGE,
                true,
                $changed,
                $changed
                    ? 'The proven stale unpaid orphan Payment Link was deactivated.'
                    : 'The Payment Link was already inactive at final Stripe revalidation.',
            );
        }

        return $outcomes;
    }

    /**
     * @param list<ManagedPaymentLinkEvidence> $paymentLinks
     *
     * @return array<string, list<ManagedPaymentLinkEvidence>>
     */
    private function groupByInvoiceNumber(array $paymentLinks): array
    {
        $groups = [];

        foreach ($paymentLinks as $paymentLink) {
            $groups[$paymentLink->metadata()->invoiceNumber()][] = $paymentLink;
        }

        return $groups;
    }

    /**
     * @param list<ManagedPaymentLinkEvidence> $paymentLinks
     *
     * @return list<ReconciliationCandidate>
     *
     * @throws ReconciliationGatewayException When Stripe evidence cannot be read safely.
     */
    private function loadCandidates(array $paymentLinks): array
    {
        $candidates = [];

        foreach ($paymentLinks as $paymentLink) {
            array_push($candidates, ...$this->stripeReader->listCandidates($paymentLink));
        }

        return $candidates;
    }

    private function outcomeFromPersistenceFailure(
        string $invoiceNumber,
        ?string $paymentLinkId,
        ReconciliationPersistenceException $exception,
        bool $wouldChange = false,
    ): CleanupItemOutcome {
        return new CleanupItemOutcome(
            $invoiceNumber,
            $paymentLinkId,
            $exception->result(),
            $wouldChange,
            false,
            $exception->getMessage(),
        );
    }
}
