<?php

/**
 * @file
 * @brief Defines creation of a credential-bound Payment Link service.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;

/**
 * @brief Runtime factory seam used by Kimai integration and deterministic tests.
 */
interface PaymentLinkServiceFactory
{
    public function create(StripeCredential $credential): PaymentLinkService;
}
