<?php

/**
 * @file
 * @brief Converts supported decimal invoice amounts to exact Stripe minor units.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Money;

use InvalidArgumentException;

/**
 * @brief Positive invoice money represented exactly in integer minor units.
 *
 * @details
 * Decimal input is parsed as text.  Binary floating-point arithmetic is never
 * used for authoritative conversion or comparison.
 */
final readonly class Money
{
    /**
     * @brief Construct money from already validated minor units.
     *
     * @param int $minorUnits Positive integer number of hundredths.
     * @param CurrencyCode $currency Supported currency.
     *
     * @throws InvalidArgumentException When the amount is not positive.
     */
    public function __construct(
        private int $minorUnits,
        private CurrencyCode $currency,
    ) {
        if ($minorUnits <= 0) {
            throw new InvalidArgumentException('Invoice amount must be greater than zero.');
        }
    }

    /**
     * @brief Convert an exact decimal amount to integer minor units.
     *
     * @param string $amount Decimal amount using at most two fractional digits.
     * @param string $currency Three-letter currency code.
     *
     * @throws InvalidArgumentException When amount syntax, precision, range, or currency is unsupported.
     */
    public static function fromDecimal(string $amount, string $currency): self
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException(
                'Invoice amount must be a non-negative decimal string with at most two fractional digits.'
            );
        }

        $fraction = $matches[2] ?? '';
        $minorString = ltrim($matches[1] . str_pad($fraction, 2, '0'), '0');
        $minorString = $minorString === '' ? '0' : $minorString;
        $phpIntMax = (string) PHP_INT_MAX;

        if (
            strlen($minorString) > strlen($phpIntMax)
            || (strlen($minorString) === strlen($phpIntMax) && strcmp($minorString, $phpIntMax) > 0)
        ) {
            throw new InvalidArgumentException('Invoice amount exceeds the supported integer range.');
        }

        return new self((int) $minorString, new CurrencyCode($currency));
    }

    /**
     * @brief Construct money from a Stripe minor-unit amount and currency.
     *
     * @param int $minorUnits Positive integer amount.
     * @param string $currency Three-letter currency code.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, new CurrencyCode($currency));
    }

    /**
     * @brief Return the exact integer Stripe amount.
     */
    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * @brief Return the normalized supported currency.
     */
    public function currency(): CurrencyCode
    {
        return $this->currency;
    }
}
