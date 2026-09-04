<?php

/**
 * @file
 * @brief Constructs an identified Stripe SDK client without performing API calls.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use Stripe\StripeClient;

/**
 * @brief Creates connector-owned Stripe clients using SDK-aligned API behavior.
 *
 * @details
 * The factory deliberately does not set a separate Stripe API version.  The
 * exact installed Stripe PHP SDK therefore controls the request API version as
 * required by ADR-004.
 */
final class StripeClientFactory
{
    public const APPLICATION_NAME = 'kimai-stripe-connector';
    public const APPLICATION_URL = 'https://github.com/wesley-dean/kimai-stripe-connector';

    /**
     * @brief Construct a Stripe client without making a remote request.
     *
     * @param StripeCredential $credential Validated restricted Stripe API key.
     */
    public function create(StripeCredential $credential): StripeClient
    {
        return new StripeClient([
            'api_key' => $credential->value(),
            'app_info' => $this->applicationInfo(),
        ]);
    }

    /**
     * @brief Return the non-secret Stripe SDK application identification fields.
     *
     * @return array{name: string, url: string}
     */
    public function applicationInfo(): array
    {
        return [
            'name' => self::APPLICATION_NAME,
            'url' => self::APPLICATION_URL,
        ];
    }
}
