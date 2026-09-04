<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoiceStore;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPersistenceException;

final class InMemoryReconciliationInvoiceStore implements ReconciliationInvoiceStore
{
    /** @var list<string> */
    public array $markPaidCalls = [];

    /**
     * @param array<string, ReconciliationInvoice> $invoices
     * @param array<string, ReconciliationPersistenceException> $lookupFailures
     * @param array<string, ReconciliationPersistenceException> $markFailures
     */
    public function __construct(
        private array $invoices = [],
        private array $lookupFailures = [],
        private array $markFailures = [],
    ) {
    }

    public function findByInvoiceNumber(
        KimaiInstanceId $instanceId,
        string $invoiceNumber,
    ): ?ReconciliationInvoice {
        if (isset($this->lookupFailures[$invoiceNumber])) {
            throw $this->lookupFailures[$invoiceNumber];
        }

        $invoice = $this->invoices[$invoiceNumber] ?? null;
        if ($invoice === null) {
            return null;
        }

        if ($invoice->metadata()->instanceId()->value() !== $instanceId->value()) {
            return null;
        }

        return $invoice;
    }

    public function markPaid(
        ReconciliationInvoice $invoice,
        ReconciliationCandidate $evidence,
    ): bool {
        $invoiceNumber = $invoice->metadata()->invoiceNumber();
        $this->markPaidCalls[] = $invoiceNumber;

        if (isset($this->markFailures[$invoiceNumber])) {
            throw $this->markFailures[$invoiceNumber];
        }

        $current = $this->invoices[$invoiceNumber] ?? null;
        if ($current === null) {
            throw new \LogicException('Test store cannot mark a missing invoice paid.');
        }

        if (
            $current->metadata()->toStripeMetadata() !== $invoice->metadata()->toStripeMetadata()
            || $current->metadata()->toStripeMetadata() !== $evidence->paymentLinkMetadata()->toStripeMetadata()
        ) {
            throw new ReconciliationPersistenceException(
                OperatorResult::CONFLICT,
                'Kimai invoice identity or financial state changed before the paid transition could be applied.',
            );
        }

        if ($current->isPaid()) {
            return false;
        }

        $this->invoices[$invoiceNumber] = new ReconciliationInvoice($current->metadata(), true);

        return true;
    }
}
