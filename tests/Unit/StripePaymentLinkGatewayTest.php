<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\StripePaymentLinkGateway;
use PHPUnit\Framework\TestCase;
use Stripe\Collection;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentLink;
use Stripe\Service\PaymentLinkService;

final class StripePaymentLinkGatewayTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testListsAndConvertsStripePaymentLinks(): void
    {
        $paymentLink = $this->stripePaymentLink('plink_existing');
        $collection = Collection::constructFrom([
            'object' => 'list',
            'data' => [$paymentLink],
            'has_more' => false,
            'url' => '/v1/payment_links',
        ]);
        $service = $this->createMock(PaymentLinkService::class);
        $service->expects(self::once())
            ->method('all')
            ->with(['limit' => 100])
            ->willReturn($collection);

        $candidates = (new StripePaymentLinkGateway($service))->listAll();

        self::assertCount(1, $candidates);
        self::assertSame('plink_existing', $candidates[0]->id());
        self::assertSame('https://buy.stripe.com/test_plink_existing', $candidates[0]->url());
    }

    public function testCreateForwardsParametersAndIdempotencyOptions(): void
    {
        $metadata = $this->metadata();
        $request = PaymentLinkCreateRequest::fromInvoiceMetadata($metadata);
        $paymentLink = $this->stripePaymentLink('plink_created');
        $service = $this->createMock(PaymentLinkService::class);
        $service->expects(self::once())
            ->method('create')
            ->with($request->parameters(), $request->requestOptions())
            ->willReturn($paymentLink);

        $candidate = (new StripePaymentLinkGateway($service))->create($request);

        self::assertSame('plink_created', $candidate->id());
    }

    public function testConnectionFailureDuringCreateIsCommitUncertain(): void
    {
        $service = $this->createMock(PaymentLinkService::class);
        $service->method('create')
            ->willThrowException(ApiConnectionException::factory('network timeout'));
        $gateway = new StripePaymentLinkGateway($service);

        try {
            $gateway->create(PaymentLinkCreateRequest::fromInvoiceMetadata($this->metadata()));
            self::fail('Expected PaymentLinkGatewayException.');
        } catch (PaymentLinkGatewayException $exception) {
            self::assertTrue($exception->commitMayHaveOccurred());
        }
    }

    public function testMalformedCreatedPaymentLinkIsCommitUncertain(): void
    {
        $bad = PaymentLink::constructFrom([
            'id' => 'plink_bad',
            'object' => 'payment_link',
            'active' => true,
            'url' => 'not-a-url',
            'metadata' => $this->metadata()->toStripeMetadata(),
        ]);
        $service = $this->createMock(PaymentLinkService::class);
        $service->method('create')->willReturn($bad);
        $gateway = new StripePaymentLinkGateway($service);

        try {
            $gateway->create(PaymentLinkCreateRequest::fromInvoiceMetadata($this->metadata()));
            self::fail('Expected PaymentLinkGatewayException.');
        } catch (PaymentLinkGatewayException $exception) {
            self::assertTrue($exception->commitMayHaveOccurred());
        }
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function stripePaymentLink(string $id): PaymentLink
    {
        return PaymentLink::constructFrom([
            'id' => $id,
            'object' => 'payment_link',
            'active' => true,
            'url' => 'https://buy.stripe.com/test_' . $id,
            'metadata' => $this->metadata()->toStripeMetadata(),
        ]);
    }
}
