<?php

/**
 * @file
 * @brief Exposes only the safe Payment Link URL to Kimai invoice rendering.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use App\Invoice\InvoiceModel;
use App\Invoice\InvoiceModelHydrator;

/**
 * @brief Hydrates the connector's customer-facing payment URL into invoice template data.
 */
final class PaymentLinkInvoiceModelHydrator implements InvoiceModelHydrator
{
    public const OPTION_KEY = 'kimai_stripe_connector.payment_url';
    public const TEMPLATE_KEY = 'invoice.stripe.payment_url';

    /**
     * @return array<string, string>
     */
    public function hydrate(InvoiceModel $model): array
    {
        $value = $model->getOptions()[self::OPTION_KEY] ?? null;

        if (!is_string($value) || $value === '') {
            return [];
        }

        return [self::TEMPLATE_KEY => $value];
    }
}
