<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use PHPUnit\Framework\TestCase;

final class PaymentLinkCreateRequestTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testBuildsCardOnlyFixedInvoicePaymentLinkParameters(): void
    {
        $metadata = $this->metadata('INV-0042', '875.10', 'usd');
        $request = PaymentLinkCreateRequest::fromInvoiceMetadata($metadata);
        $parameters = $request->parameters();

        self::assertSame([
            [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Kimai invoice payment',
                    ],
                    'unit_amount' => 87510,
                ],
                'quantity' => 1,
            ],
        ], $parameters['line_items']);
        self::assertSame(['card'], $parameters['payment_method_types']);
        self::assertSame($metadata->toStripeMetadata(), $parameters['metadata']);
        self::assertSame(
            $metadata->toStripeMetadata(),
            $parameters['payment_intent_data']['metadata'],
        );
    }

    public function testIdempotencyKeyIsStableForSameLogicalInvoiceRequest(): void
    {
        $first = PaymentLinkCreateRequest::fromInvoiceMetadata(
            $this->metadata('INV-0042', '875.10', 'usd'),
        );
        $second = PaymentLinkCreateRequest::fromInvoiceMetadata(
            $this->metadata('INV-0042', '875.10', 'usd'),
        );

        self::assertSame($first->requestOptions(), $second->requestOptions());
        self::assertStringStartsWith(
            'kimai-stripe-connector:payment-link:v1:',
            $first->requestOptions()['idempotency_key'],
        );
    }

    public function testIdempotencyKeyChangesWhenFinancialMeaningChanges(): void
    {
        $first = PaymentLinkCreateRequest::fromInvoiceMetadata(
            $this->metadata('INV-0042', '875.10', 'usd'),
        );
        $second = PaymentLinkCreateRequest::fromInvoiceMetadata(
            $this->metadata('INV-0042', '875.11', 'usd'),
        );

        self::assertNotSame(
            $first->requestOptions()['idempotency_key'],
            $second->requestOptions()['idempotency_key'],
        );
    }

    private function metadata(string $invoiceNumber, string $amount, string $currency): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            $invoiceNumber,
            Money::fromDecimal($amount, $currency),
        );
    }
}
