<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

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

    public function findByInvoiceNumber(string $invoiceNumber): ?ReconciliationInvoice
    {
        if (isset($this->lookupFailures[$invoiceNumber])) {
            throw $this->lookupFailures[$invoiceNumber];
        }

        return $this->invoices[$invoiceNumber] ?? null;
    }

    public function markPaid(ReconciliationInvoice $invoice): bool
    {
        $invoiceNumber = $invoice->metadata()->invoiceNumber();
        $this->markPaidCalls[] = $invoiceNumber;

        if (isset($this->markFailures[$invoiceNumber])) {
            throw $this->markFailures[$invoiceNumber];
        }

        $current = $this->invoices[$invoiceNumber] ?? null;
        if ($current === null) {
            throw new \LogicException('Test store cannot mark a missing invoice paid.');
        }

        if ($current->isPaid()) {
            return false;
        }

        $this->invoices[$invoiceNumber] = new ReconciliationInvoice($current->metadata(), true);

        return true;
    }
}
