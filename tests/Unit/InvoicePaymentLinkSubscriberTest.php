<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use App\Event\InvoicePreRenderEvent;
use App\Invoice\InvoiceModel;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\InvoicePaymentLinkSubscriber;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\KimaiInvoiceMetadataFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGenerationException;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkInvoiceModelHydrator;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkService;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkServiceFactory;
use KimaiStripeConnectorTests\Support\FixedCalculator;
use KimaiStripeConnectorTests\Support\StubPaymentLinkServiceFactory;
use KimaiStripeConnectorTests\Support\SubscriberGateway;
use PHPUnit\Framework\TestCase;

final class InvoicePaymentLinkSubscriberTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    private const OWNED_ENVIRONMENT = [
        'KIMAI_STRIPE_CONNECTOR_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_INSTANCE_ID',
        'KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS',
        'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY',
        'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE',
    ];

    protected function tearDown(): void
    {
        foreach (self::OWNED_ENVIRONMENT as $name) {
            putenv($name);
        }

        parent::tearDown();
    }

    public function testPreviewReturnsBeforeConfigurationOrStripeAccess(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=definitely-not-a-boolean');
        $factory = new StubPaymentLinkServiceFactory(new PaymentLinkService(new SubscriberGateway([])));
        $model = new InvoiceModel(true, new FixedCalculator(875.1), 'INV-0042', 'USD');

        $this->subscriber($factory)->onInvoicePreRender(new InvoicePreRenderEvent($model));

        self::assertSame(0, $factory->createCalls);
        self::assertSame([], $model->getOptions());
        self::assertSame([], $model->toArray());
    }

    public function testDisabledConnectorLeavesInvoiceUntouched(): void
    {
        $factory = new StubPaymentLinkServiceFactory(new PaymentLinkService(new SubscriberGateway([])));
        $model = new InvoiceModel(false, new FixedCalculator(875.1), 'INV-0042', 'USD');

        $this->subscriber($factory)->onInvoicePreRender(new InvoicePreRenderEvent($model));

        self::assertSame(0, $factory->createCalls);
        self::assertSame([], $model->getOptions());
    }

    public function testActiveConnectorHydratesOnlySafePaymentUrl(): void
    {
        $this->enablePaymentLinks();
        $expected = $this->metadata();
        $candidate = PaymentLinkCandidate::fromStripePayload([
            'id' => 'plink_existing',
            'url' => 'https://buy.stripe.com/test_plink_existing',
            'active' => true,
            'metadata' => $expected->toStripeMetadata(),
        ]);
        $factory = new StubPaymentLinkServiceFactory(
            new PaymentLinkService(new SubscriberGateway([$candidate])),
        );
        $model = new InvoiceModel(false, new FixedCalculator(875.1), 'INV-0042', 'USD');

        $this->subscriber($factory)->onInvoicePreRender(new InvoicePreRenderEvent($model));

        self::assertSame(1, $factory->createCalls);
        self::assertSame([
            PaymentLinkInvoiceModelHydrator::OPTION_KEY => $candidate->url(),
        ], $model->getOptions());
        self::assertSame([
            PaymentLinkInvoiceModelHydrator::TEMPLATE_KEY => $candidate->url(),
        ], $model->toArray());
    }

    public function testAutomaticFailureStopsInvoiceRenderingWithOperatorCategory(): void
    {
        $this->enablePaymentLinks();
        $factory = new StubPaymentLinkServiceFactory(
            new PaymentLinkService(
                new SubscriberGateway(
                    new PaymentLinkGatewayException('network unavailable'),
                ),
            ),
        );
        $model = new InvoiceModel(false, new FixedCalculator(875.1), 'INV-0042', 'USD');

        $this->expectException(PaymentLinkGenerationException::class);
        $this->expectExceptionMessage('RETRYABLE');

        $this->subscriber($factory)->onInvoicePreRender(new InvoicePreRenderEvent($model));
    }

    private function subscriber(PaymentLinkServiceFactory $factory): InvoicePaymentLinkSubscriber
    {
        return new InvoicePaymentLinkSubscriber(
            new EnvironmentConfigurationLoader(),
            $factory,
            new KimaiInvoiceMetadataFactory(),
            new PaymentLinkInvoiceModelHydrator(),
        );
    }

    private function enablePaymentLinks(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=' . self::INSTANCE_ID);
        putenv('KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=' . $this->syntheticRestrictedKey());
    }

    private function syntheticRestrictedKey(): string
    {
        return 'rk_' . 'test_' . str_repeat('x', 24);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'USD'),
        );
    }
}
