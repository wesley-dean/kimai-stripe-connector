<?php

/**
 * @file
 * @brief Represents one connector-managed Stripe Payment Link for evidence discovery.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable Payment Link identity and validated connector metadata.
 *
 * @details
 * Reconciliation needs only identity and metadata.  Cleanup additionally needs
 * Stripe's creation timestamp and active flag so age and current deactivation
 * state are based on Stripe evidence rather than local assumptions.  The optional
 * defaults preserve pure/unit fixtures that do not exercise cleanup; production
 * Stripe discovery populates both values when Stripe supplies them.
 */
final readonly class ManagedPaymentLinkEvidence
{
    /**
     * @throws InvalidArgumentException When the Payment Link identifier is blank or the timestamp is invalid.
     */
    public function __construct(
        private string $id,
        private InvoiceMetadata $metadata,
        private ?int $createdAt = null,
        private ?bool $active = null,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Payment Link ID must not be blank.');
        }

        if ($createdAt !== null && $createdAt <= 0) {
            throw new InvalidArgumentException('Payment Link creation timestamp must be positive when supplied.');
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

    /** @brief Return Stripe's Unix creation timestamp when retained by discovery. */
    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    /** @brief Return Stripe's active flag when retained by discovery. */
    public function active(): ?bool
    {
        return $this->active;
    }
}
