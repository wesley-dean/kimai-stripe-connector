<?php

/**
 * @file
 * @brief Provides deterministic redaction of known secret values before logging.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Logging;

/**
 * @brief Removes known non-empty secret values from diagnostic text.
 */
final class SecretRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * @brief Replace each known secret occurrence with a fixed marker.
     *
     * @param string $text Diagnostic text that may contain secret values.
     * @param iterable<string> $secrets Secret values to remove.
     */
    public function redact(string $text, iterable $secrets): string
    {
        $redacted = $text;

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $redacted = str_replace($secret, self::REDACTED, $redacted);
        }

        return $redacted;
    }
}
