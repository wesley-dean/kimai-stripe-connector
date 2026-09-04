<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use App\Invoice\InvoiceModel;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\KimaiInvoiceMetadataFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGenerationException;
use KimaiStripeConnectorTests\Support\FixedCalculator;
use PHPUnit\Framework\TestCase;

final class KimaiInvoiceMetadataFactoryTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testConvertsOrdinaryKimaiFloatThroughExactDecimalBoundary(): void
    {
        $model = new InvoiceModel(false, new FixedCalculator(875.1), 'INV-0042', 'USD');

        $metadata = (new KimaiInvoiceMetadataFactory())->create(
            $model,
            new KimaiInstanceId(self::INSTANCE_ID),
        );

        self::assertSame('INV-0042', $metadata->invoiceNumber());
        self::assertSame(87510, $metadata->money()->minorUnits());
        self::assertSame('usd', $metadata->money()->currency()->value());
    }

    public function testBinaryArtifactPrecisionFailsClosedRatherThanRounding(): void
    {
        $model = new InvoiceModel(false, new FixedCalculator(0.1 + 0.2), 'INV-0042', 'USD');

        $this->expectException(PaymentLinkGenerationException::class);
        $this->expectExceptionMessage('cannot be represented exactly');

        (new KimaiInvoiceMetadataFactory())->create(
            $model,
            new KimaiInstanceId(self::INSTANCE_ID),
        );
    }

    public function testMissingCalculatorFailsClosed(): void
    {
        $model = new InvoiceModel(false, null, 'INV-0042', 'USD');

        $this->expectException(PaymentLinkGenerationException::class);
        $this->expectExceptionMessage('no calculator');

        (new KimaiInvoiceMetadataFactory())->create(
            $model,
            new KimaiInstanceId(self::INSTANCE_ID),
        );
    }
}
