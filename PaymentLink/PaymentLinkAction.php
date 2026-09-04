<?php

/**
 * @file
 * @brief Enumerates deterministic Payment Link planning outcomes.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

/**
 * @brief Action selected before any Stripe mutation is attempted.
 */
enum PaymentLinkAction: string
{
    case PREVIEW = 'PREVIEW';
    case REUSE = 'REUSE';
    case CREATE = 'CREATE';
    case CONFLICT = 'CONFLICT';
    case MANUAL_REVIEW_REQUIRED = 'MANUAL_REVIEW_REQUIRED';
}
