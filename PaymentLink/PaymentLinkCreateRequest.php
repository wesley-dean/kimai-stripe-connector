<?php

/**
 * @file
 * @brief Builds deterministic Stripe Payment Link create parameters and request options.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;

/**
 * @brief Immutable Stripe create request derived from exact Kimai invoice metadata.
 */
final readonly class PaymentLinkCreateRequest
{
    /**
     * @param array<string, mixed> $parameters Stripe Payment Link create parameters.
     * @param array<string, string> $requestOptions Stripe SDK request options.
     */
    private function __construct(
        private array $parameters,
        private array $requestOptions,
    ) {
    }

    /**
     * @brief Build the card-only Payment Link request for one fixed Kimai invoice.
     *
     * @details
     * Connector metadata is attached to the Payment Link and declaratively copied
     * onto generated PaymentIntents.  Stripe itself copies Payment Link metadata
     * onto Checkout Sessions.  The deterministic idempotency key protects immediate
     * retries, while durable metadata remains the longer-lived recovery mechanism.
     */
    public static function fromInvoiceMetadata(InvoiceMetadata $metadata): self
    {
        $stripeMetadata = $metadata->toStripeMetadata();

        return new self(
            [
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => $metadata->money()->currency()->value(),
                            'product_data' => [
                                'name' => 'Kimai invoice payment',
                            ],
                            'unit_amount' => $metadata->money()->minorUnits(),
                        ],
                        'quantity' => 1,
                    ],
                ],
                'metadata' => $stripeMetadata,
                'payment_intent_data' => [
                    'metadata' => $stripeMetadata,
                ],
                'payment_method_types' => ['card'],
            ],
            [
                'idempotency_key' => self::idempotencyKey($stripeMetadata),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @return array<string, string> */
    public function requestOptions(): array
    {
        return $this->requestOptions;
    }

    /**
     * @param array<string, string> $metadata Exact ordered version-1 metadata.
     */
    private static function idempotencyKey(array $metadata): string
    {
        $basis = '';

        foreach ($metadata as $key => $value) {
            $basis .= $key . "\0" . $value . "\0";
        }

        return 'kimai-stripe-connector:payment-link:v1:' . hash('sha256', $basis);
    }
}
