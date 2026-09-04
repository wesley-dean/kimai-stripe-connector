<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoiceStore;

final class SequencedReconciliationInvoiceStore implements ReconciliationInvoiceStore
{
    public int $lookupCount = 0;

    /** @param list<ReconciliationInvoice|null> $sequence */
    public function __construct(private array $sequence)
    {
    }

    public function findByInvoiceNumber(
        KimaiInstanceId $instanceId,
        string $invoiceNumber,
    ): ?ReconciliationInvoice {
        $index = $this->lookupCount;
        ++$this->lookupCount;
        $invoice = $this->sequence[$index] ?? null;

        if ($invoice === null) {
            return null;
        }

        if (
            $invoice->metadata()->instanceId()->value() !== $instanceId->value()
            || $invoice->metadata()->invoiceNumber() !== $invoiceNumber
        ) {
            return null;
        }

        return $invoice;
    }

    public function markPaid(
        ReconciliationInvoice $invoice,
        ReconciliationCandidate $evidence,
    ): bool {
        throw new \LogicException('Sequenced lookup test store does not support paid-state mutation.');
    }
}
