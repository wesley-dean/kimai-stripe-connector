<?php

/**
 * @file
 * @brief Represents one connector-managed Stripe Payment Link for reconciliation discovery.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable Payment Link identity and validated connector metadata.
 */
final readonly class ManagedPaymentLinkEvidence
{
    /**
     * @throws InvalidArgumentException When the Payment Link identifier is blank.
     */
    public function __construct(
        private string $id,
        private InvoiceMetadata $metadata,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Payment Link ID must not be blank.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function metadata(): InvoiceMetadata
    {
        return $this->metadata;
    }
}
