<?php

/**
 * @file
 * @brief Converts Kimai's invoice model into the connector's exact metadata contract.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use App\Invoice\InvoiceModel;
use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use Throwable;

/**
 * @brief Validates Kimai invoice identity and money at the integration boundary.
 */
final class KimaiInvoiceMetadataFactory
{
    /**
     * @brief Build exact connector metadata from one non-preview Kimai invoice model.
     *
     * @details
     * Kimai 2.65 exposes calculator totals as floats.  Money::fromFloat() performs
     * no financial arithmetic or rounding: it serializes the float to PHP's
     * shortest JSON decimal representation and feeds that text into the exact
     * two-decimal parser.  Values carrying binary-artifact precision beyond the
     * supported currency precision therefore fail closed.
     *
     * @throws PaymentLinkGenerationException When the model cannot be represented by the connector contract.
     */
    public function create(InvoiceModel $model, KimaiInstanceId $instanceId): InvoiceMetadata
    {
        $calculator = $model->getCalculator();
        if ($calculator === null) {
            throw new PaymentLinkGenerationException('Kimai invoice model has no calculator.');
        }

        try {
            return new InvoiceMetadata(
                $instanceId,
                $model->getInvoiceNumber(),
                Money::fromFloat($calculator->getTotal(), $model->getCurrency()),
            );
        } catch (InvalidArgumentException $exception) {
            throw new PaymentLinkGenerationException(
                'Kimai invoice total cannot be represented exactly by the connector money policy.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new PaymentLinkGenerationException(
                'Kimai invoice identity could not be prepared for Stripe.',
                previous: $exception,
            );
        }
    }
}
