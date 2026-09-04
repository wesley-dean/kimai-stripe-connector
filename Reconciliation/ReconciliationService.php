<?php

/**
 * @file
 * @brief Orchestrates read-only Stripe evidence, planning, and optional Kimai paid-state persistence.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;

/**
 * @brief Capability-level reconciliation orchestration independent of Kimai framework classes.
 */
final readonly class ReconciliationService
{
    public function __construct(
        private ReconciliationStripeGateway $stripe,
        private ReconciliationInvoiceStore $invoices,
        private ReconciliationPlanner $planner,
    ) {
    }

    /**
     * @return list<ReconciliationItemOutcome>
     *
     * @throws ReconciliationGatewayException When Payment Link discovery cannot begin safely.
     */
    public function reconcile(KimaiInstanceId $instanceId, bool $dryRun = false): array
    {
        $groups = $this->groupByInvoiceNumber($this->stripe->listManagedPaymentLinks($instanceId));
        ksort($groups, SORT_STRING);
        $outcomes = [];

        foreach ($groups as $invoiceNumber => $paymentLinks) {
            try {
                $candidates = [];
                foreach ($paymentLinks as $paymentLink) {
                    array_push($candidates, ...$this->stripe->listCandidates($paymentLink));
                }
            } catch (ReconciliationGatewayException $exception) {
                $outcomes[] = new ReconciliationItemOutcome(
                    $invoiceNumber,
                    $exception->result(),
                    false,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            try {
                $invoice = $this->invoices->findByInvoiceNumber($invoiceNumber);
            } catch (ReconciliationPersistenceException $exception) {
                $outcomes[] = new ReconciliationItemOutcome(
                    $invoiceNumber,
                    $exception->result(),
                    false,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            if ($invoice === null) {
                $outcomes[] = $this->orphanOutcome($invoiceNumber, $paymentLinks[0], $candidates);
                continue;
            }

            $plan = $this->planner->plan($invoice->isPaid(), $invoice->metadata(), $candidates);
            if (!$plan->shouldMarkPaid()) {
                $outcomes[] = new ReconciliationItemOutcome(
                    $invoiceNumber,
                    $plan->result(),
                    false,
                    false,
                    $plan->reason(),
                );
                continue;
            }

            if ($dryRun) {
                $outcomes[] = new ReconciliationItemOutcome(
                    $invoiceNumber,
                    OperatorResult::SUCCESS,
                    true,
                    false,
                    'Dry run: exact Stripe evidence would mark the Kimai invoice paid.',
                );
                continue;
            }

            try {
                $changed = $this->invoices->markPaid($invoice);
            } catch (ReconciliationPersistenceException $exception) {
                $outcomes[] = new ReconciliationItemOutcome(
                    $invoiceNumber,
                    $exception->result(),
                    true,
                    false,
                    $exception->getMessage(),
                );
                continue;
            }

            $outcomes[] = new ReconciliationItemOutcome(
                $invoiceNumber,
                $changed ? OperatorResult::SUCCESS : OperatorResult::NO_CHANGE,
                true,
                $changed,
                $changed
                    ? 'Exact Stripe evidence was proven and the Kimai invoice was marked paid.'
                    : 'Kimai was already paid when the idempotent transition was applied.',
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
     * @param list<ReconciliationCandidate> $candidates
     */
    private function orphanOutcome(
        string $invoiceNumber,
        ManagedPaymentLinkEvidence $paymentLink,
        array $candidates,
    ): ReconciliationItemOutcome {
        $plan = $this->planner->plan(false, $paymentLink->metadata(), $candidates);

        if ($plan->shouldMarkPaid()) {
            return new ReconciliationItemOutcome(
                $invoiceNumber,
                OperatorResult::MANUAL_REVIEW_REQUIRED,
                false,
                false,
                'Successful Stripe payment evidence has no matching Kimai invoice.',
            );
        }

        return new ReconciliationItemOutcome(
            $invoiceNumber,
            $plan->result(),
            false,
            false,
            $plan->result() === OperatorResult::NO_CHANGE
                ? 'Connector-managed Stripe evidence has no matching Kimai invoice and proves no payment.'
                : $plan->reason(),
        );
    }
}
