<?php

/**
 * @file
 * @brief Defines the stable operator-facing result taxonomy from ADR-013.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Result;

/**
 * @brief Stable result categories shared by CLI output, logs, tests, and runbooks.
 */
enum OperatorResult: string
{
    case SUCCESS = 'SUCCESS';
    case NO_CHANGE = 'NO_CHANGE';
    case RETRYABLE = 'RETRYABLE';
    case CONFIGURATION_ERROR = 'CONFIGURATION_ERROR';
    case CONFLICT = 'CONFLICT';
    case MANUAL_REVIEW_REQUIRED = 'MANUAL_REVIEW_REQUIRED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @brief Report whether the result represents successful command completion.
     */
    public function isSuccessful(): bool
    {
        return $this === self::SUCCESS || $this === self::NO_CHANGE;
    }
}
