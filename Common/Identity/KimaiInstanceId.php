<?php

/**
 * @file
 * @brief Defines the stable Kimai instance UUID used for Stripe correlation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;

/**
 * @brief Validated canonical UUID identifying one Kimai deployment namespace.
 */
final readonly class KimaiInstanceId
{
    /**
     * @brief Construct a stable Kimai instance identifier.
     *
     * @param string $value Canonical UUID string.
     *
     * @throws ConfigurationException When the UUID is malformed or nil.
     */
    public function __construct(private string $value)
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        if (preg_match($pattern, $value) !== 1 || strtolower($value) === '00000000-0000-0000-0000-000000000000') {
            throw new ConfigurationException('Kimai instance ID must be a non-nil canonical UUID.');
        }
    }

    /**
     * @brief Return the normalized lowercase UUID.
     */
    public function value(): string
    {
        return strtolower($this->value);
    }
}
