<?php

/**
 * @file
 * @brief Defines the initially supported Stripe two-decimal currency set.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Money;

use InvalidArgumentException;

/**
 * @brief Normalized Stripe currency code within the accepted v1 money scope.
 *
 * @details
 * Version 1 supports Stripe presentment currencies that use ordinary two-decimal
 * charge semantics.  Zero-decimal currencies and Stripe special-case currencies
 * are intentionally excluded until their representation rules receive dedicated
 * implementation and tests.
 */
final readonly class CurrencyCode
{
    /** @var array<string, true> */
    private const SUPPORTED = [
        'aed' => true,
        'afn' => true,
        'all' => true,
        'amd' => true,
        'ang' => true,
        'aoa' => true,
        'ars' => true,
        'aud' => true,
        'awg' => true,
        'azn' => true,
        'bam' => true,
        'bbd' => true,
        'bdt' => true,
        'bmd' => true,
        'bnd' => true,
        'bob' => true,
        'brl' => true,
        'bsd' => true,
        'bwp' => true,
        'byn' => true,
        'bzd' => true,
        'cad' => true,
        'cdf' => true,
        'chf' => true,
        'cny' => true,
        'cop' => true,
        'crc' => true,
        'cve' => true,
        'czk' => true,
        'dkk' => true,
        'dop' => true,
        'dzd' => true,
        'egp' => true,
        'etb' => true,
        'eur' => true,
        'fjd' => true,
        'fkp' => true,
        'gbp' => true,
        'gel' => true,
        'gip' => true,
        'gmd' => true,
        'gtq' => true,
        'gyd' => true,
        'hkd' => true,
        'hnl' => true,
        'htg' => true,
        'idr' => true,
        'ils' => true,
        'inr' => true,
        'jmd' => true,
        'kes' => true,
        'kgs' => true,
        'khr' => true,
        'kyd' => true,
        'kzt' => true,
        'lak' => true,
        'lbp' => true,
        'lkr' => true,
        'lrd' => true,
        'lsl' => true,
        'mad' => true,
        'mdl' => true,
        'mkd' => true,
        'mmk' => true,
        'mnt' => true,
        'mop' => true,
        'mur' => true,
        'mvr' => true,
        'mwk' => true,
        'mxn' => true,
        'myr' => true,
        'mzn' => true,
        'nad' => true,
        'ngn' => true,
        'nio' => true,
        'nok' => true,
        'npr' => true,
        'nzd' => true,
        'pab' => true,
        'pen' => true,
        'pgk' => true,
        'php' => true,
        'pkr' => true,
        'pln' => true,
        'qar' => true,
        'ron' => true,
        'rsd' => true,
        'rub' => true,
        'sar' => true,
        'sbd' => true,
        'scr' => true,
        'sek' => true,
        'sgd' => true,
        'shp' => true,
        'sle' => true,
        'sos' => true,
        'srd' => true,
        'std' => true,
        'szl' => true,
        'thb' => true,
        'tjs' => true,
        'top' => true,
        'try' => true,
        'ttd' => true,
        'tzs' => true,
        'uah' => true,
        'usd' => true,
        'uyu' => true,
        'uzs' => true,
        'wst' => true,
        'xcd' => true,
        'xcg' => true,
        'yer' => true,
        'zar' => true,
        'zmw' => true,
    ];

    /**
     * @brief Construct a supported normalized currency code.
     *
     * @param string $value Three-letter currency code.
     *
     * @throws InvalidArgumentException When the code is malformed or outside the v1 scope.
     */
    public function __construct(private string $value)
    {
        $normalized = strtolower($value);

        if (preg_match('/^[a-z]{3}$/', $normalized) !== 1 || !isset(self::SUPPORTED[$normalized])) {
            throw new InvalidArgumentException('Currency is unsupported by the version-1 two-decimal money policy.');
        }
    }

    /**
     * @brief Return the lowercase Stripe currency code.
     */
    public function value(): string
    {
        return strtolower($this->value);
    }
}
