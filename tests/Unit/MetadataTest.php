<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\MetadataException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testWritesExactlyVersionOneCorrelationFields(): void
    {
        $metadata = new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'USD'),
        );

        self::assertSame([
            'integration' => 'kimai-stripe-connector',
            'integration_version' => '1',
            'kimai_instance' => self::INSTANCE_ID,
            'invoice_number' => 'INV-0042',
            'invoice_amount' => '87510',
            'invoice_currency' => 'usd',
        ], $metadata->toStripeMetadata());
    }

    public function testParsesRequiredFieldsAndIgnoresUnrelatedMetadata(): void
    {
        $metadata = InvoiceMetadata::fromStripeMetadata([
            'integration' => 'kimai-stripe-connector',
            'integration_version' => '1',
            'kimai_instance' => self::INSTANCE_ID,
            'invoice_number' => 'INV-0042',
            'invoice_amount' => '87510',
            'invoice_currency' => 'usd',
            'operator_note' => 'unrelated metadata does not redefine identity',
        ]);

        self::assertSame('INV-0042', $metadata->invoiceNumber());
        self::assertSame(self::INSTANCE_ID, $metadata->instanceId()->value());
        self::assertSame(87510, $metadata->money()->minorUnits());
        self::assertSame('usd', $metadata->money()->currency()->value());
    }

    #[DataProvider('invalidMetadataProvider')]
    public function testMalformedOrUnknownMetadataFailsClosed(array $metadata): void
    {
        $this->expectException(MetadataException::class);

        InvoiceMetadata::fromStripeMetadata($metadata);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidMetadataProvider(): iterable
    {
        $valid = [
            'integration' => 'kimai-stripe-connector',
            'integration_version' => '1',
            'kimai_instance' => self::INSTANCE_ID,
            'invoice_number' => 'INV-0042',
            'invoice_amount' => '87510',
            'invoice_currency' => 'usd',
        ];

        yield 'unknown integration' => [[...$valid, 'integration' => 'other-integration']];
        yield 'unknown version' => [[...$valid, 'integration_version' => '2']];
        yield 'missing instance' => [array_diff_key($valid, ['kimai_instance' => true])];
        yield 'malformed instance' => [[...$valid, 'kimai_instance' => 'not-a-uuid']];
        yield 'blank invoice' => [[...$valid, 'invoice_number' => '   ']];
        yield 'zero amount' => [[...$valid, 'invoice_amount' => '0']];
        yield 'decimal amount' => [[...$valid, 'invoice_amount' => '875.10']];
        yield 'uppercase currency' => [[...$valid, 'invoice_currency' => 'USD']];
        yield 'unsupported currency' => [[...$valid, 'invoice_currency' => 'jpy']];
    }

    public function testInvoiceNumberCannotExceedStripeMetadataValueLimit(): void
    {
        $this->expectException(MetadataException::class);

        new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            str_repeat('x', 501),
            Money::fromDecimal('1.00', 'usd'),
        );
    }
}
