<?php

/**
 * @file
 * @brief Represents the Kimai-side invoice state consumed by reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable Kimai invoice snapshot used by the reconciliation service.
 */
final readonly class ReconciliationInvoice
{
    public function __construct(
        private InvoiceMetadata $metadata,
        private bool $paid,
    ) {
    }

    public function metadata(): InvoiceMetadata
    {
        return $this->metadata;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }
}
