<?php

/**
 * @file
 * @brief Defines the validated Stripe restricted-key credential primitive.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration;

/**
 * @brief Holds one validated Stripe restricted API credential.
 *
 * @details
 * The connector accepts restricted Stripe API keys only.  The value is never
 * rendered implicitly; callers must deliberately request it when constructing
 * the Stripe client.
 */
final readonly class StripeCredential
{
    /**
     * @brief Construct a validated restricted Stripe credential.
     *
     * @param string $value Secret Stripe restricted API key.
     * @param CredentialSource $source Non-secret delivery source.
     *
     * @throws ConfigurationException When the value is not a restricted test or live key.
     */
    public function __construct(
        private string $value,
        private CredentialSource $source,
    ) {
        if (preg_match('/^rk_(?:test|live)_[^\\s]+$/', $value) !== 1) {
            throw new ConfigurationException(
                'Stripe credential must be a restricted API key with an rk_test_ or rk_live_ prefix.'
            );
        }
    }

    /**
     * @brief Return the secret value for deliberate use at the Stripe boundary.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * @brief Return the non-secret credential delivery source.
     */
    public function source(): CredentialSource
    {
        return $this->source;
    }

    /**
     * @brief Report whether this credential addresses Stripe live mode.
     */
    public function isLive(): bool
    {
        return str_starts_with($this->value, 'rk_live_');
    }
}
