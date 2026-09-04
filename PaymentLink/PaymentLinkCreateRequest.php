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
    private function __construct(private InvoiceMetadata $metadata)
    {
    }

    /**
     * @brief Build the card-only Payment Link request for one fixed Kimai invoice.
     *
     * @details
     * Connector metadata is attached to the Payment Link and declaratively copied
     * onto generated PaymentIntents.  Stripe itself copies Payment Link metadata
     * onto Checkout Sessions.  The completed-session limit reduces accidental
     * duplicate payment through the same link.  The deterministic idempotency key
     * protects immediate retries, while durable metadata remains the longer-lived
     * recovery mechanism.
     */
    public static function fromInvoiceMetadata(InvoiceMetadata $metadata): self
    {
        return new self($metadata);
    }

    /**
     * @return array{
     *     line_items: array{0: array{
     *         price_data: array{currency: string, product_data: array{name: string}, unit_amount: int},
     *         quantity: int
     *     }},
     *     metadata: array<string, string>,
     *     payment_intent_data: array{metadata: array<string, string>},
     *     payment_method_types: array{0: string},
     *     restrictions: array{completed_sessions: array{limit: int}}
     * }
     */
    public function parameters(): array
    {
        $stripeMetadata = $this->metadata->toStripeMetadata();

        return [
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $this->metadata->money()->currency()->value(),
                        'product_data' => [
                            'name' => 'Kimai invoice payment',
                        ],
                        'unit_amount' => $this->metadata->money()->minorUnits(),
                    ],
                    'quantity' => 1,
                ],
            ],
            'metadata' => $stripeMetadata,
            'payment_intent_data' => [
                'metadata' => $stripeMetadata,
            ],
            'payment_method_types' => ['card'],
            'restrictions' => [
                'completed_sessions' => [
                    'limit' => 1,
                ],
            ],
        ];
    }

    /** @return array{idempotency_key: string} */
    public function requestOptions(): array
    {
        return [
            'idempotency_key' => self::idempotencyKey($this->metadata->toStripeMetadata()),
        ];
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
