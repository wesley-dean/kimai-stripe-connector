<?php

/**
 * @file
 * @brief Defines the validated connector runtime configuration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;

/**
 * @brief Immutable validated connector configuration.
 */
final readonly class ConnectorConfiguration
{
    /**
     * @brief Construct a validated runtime configuration snapshot.
     *
     * @param bool $enabled Global connector enablement.
     * @param bool $paymentLinksEnabled Payment Link capability switch.
     * @param bool $reconciliationEnabled Reconciliation capability switch.
     * @param int $cleanupGraceDays Minimum orphan cleanup grace period.
     * @param KimaiInstanceId|null $instanceId Stable Kimai identity when required.
     * @param StripeCredential|null $stripeCredential Stripe credential when required.
     */
    public function __construct(
        private bool $enabled,
        private bool $paymentLinksEnabled,
        private bool $reconciliationEnabled,
        private int $cleanupGraceDays,
        private ?KimaiInstanceId $instanceId,
        private ?StripeCredential $stripeCredential,
    ) {
    }

    /** @brief Return global connector enablement. */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @brief Return Payment Link capability enablement. */
    public function paymentLinksEnabled(): bool
    {
        return $this->paymentLinksEnabled;
    }

    /** @brief Return reconciliation capability enablement. */
    public function reconciliationEnabled(): bool
    {
        return $this->reconciliationEnabled;
    }

    /** @brief Return the configured orphan cleanup grace period. */
    public function cleanupGraceDays(): int
    {
        return $this->cleanupGraceDays;
    }

    /** @brief Return the stable Kimai identity when configured. */
    public function instanceId(): ?KimaiInstanceId
    {
        return $this->instanceId;
    }

    /** @brief Return the Stripe credential when configured. */
    public function stripeCredential(): ?StripeCredential
    {
        return $this->stripeCredential;
    }

    /**
     * @brief Return configuration fields that are safe for structured logging.
     *
     * @return array<string, bool|int|string|null>
     */
    public function safeLogContext(): array
    {
        return [
            'enabled' => $this->enabled,
            'payment_links_enabled' => $this->paymentLinksEnabled,
            'reconciliation_enabled' => $this->reconciliationEnabled,
            'cleanup_grace_days' => $this->cleanupGraceDays,
            'kimai_instance' => $this->instanceId?->value(),
            'stripe_credential_source' => $this->stripeCredential?->source()->value,
        ];
    }
}
