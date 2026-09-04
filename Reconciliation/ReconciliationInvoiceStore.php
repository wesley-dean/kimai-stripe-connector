<?php

/**
 * @file
 * @brief Defines the Kimai invoice lookup and paid-state persistence boundary.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

/**
 * @brief Minimal Kimai-side persistence boundary for reconciliation.
 */
interface ReconciliationInvoiceStore
{
    /**
     * @throws ReconciliationPersistenceException When Kimai invoice lookup fails.
     */
    public function findByInvoiceNumber(string $invoiceNumber): ?ReconciliationInvoice;

    /**
     * @return bool True when this call changed Kimai from unpaid to paid, false when already paid.
     *
     * @throws ReconciliationPersistenceException When the paid transition cannot be confirmed.
     */
    public function markPaid(ReconciliationInvoice $invoice): bool;
}
