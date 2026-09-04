<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ChargeEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\CheckoutSessionEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\PaymentIntentEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconcileCommand;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPlanner;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationService;
use KimaiStripeConnectorTests\Support\InMemoryReconciliationInvoiceStore;
use KimaiStripeConnectorTests\Support\StubReconciliationServiceFactory;
use KimaiStripeConnectorTests\Support\StubReconciliationStripeGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileCommandTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    /** @var list<string> */
    private array $environmentNames = [
        'KIMAI_STRIPE_CONNECTOR_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_INSTANCE_ID',
        'KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED',
        'KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS',
        'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY',
        'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE',
    ];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->environmentNames as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        parent::tearDown();
    }

    public function testDisabledConfigurationReturnsSuccessfulNoChangeWithoutFactoryUse(): void
    {
        $factory = new StubReconciliationServiceFactory($this->service(new StubReconciliationStripeGateway([])));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('[NO_CHANGE]', $tester->getDisplay());
        self::assertSame([], $factory->credentials);
    }

    public function testActiveConfigurationWithoutCredentialReturnsConfigurationError(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=' . self::INSTANCE_ID);
        $factory = new StubReconciliationServiceFactory($this->service(new StubReconciliationStripeGateway([])));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_CONFIGURATION_ERROR, $exit);
        self::assertStringContainsString('[CONFIGURATION_ERROR]', $tester->getDisplay());
        self::assertSame([], $factory->credentials);
    }

    public function testDryRunReportsIntendedPaidTransitionWithoutMutation(): void
    {
        $this->enableReconciliation();
        $metadata = $this->metadata();
        $stripe = new StubReconciliationStripeGateway(
            [new ManagedPaymentLinkEvidence('plink_1', $metadata)],
            ['plink_1' => [$this->successfulCandidate($metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);
        $factory = new StubReconciliationServiceFactory($this->service($stripe, $store));
        $tester = $this->tester($factory);

        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(ReconcileCommand::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('[SUCCESS]', $tester->getDisplay());
        self::assertStringContainsString('would_change=yes changed=no', $tester->getDisplay());
        self::assertSame([], $store->markPaidCalls);
        self::assertCount(1, $factory->credentials);
    }

    public function testConflictOutcomeUsesStableExitCode(): void
    {
        $this->enableReconciliation();
        $metadata = $this->metadata();
        $stripe = new StubReconciliationStripeGateway(
            [
                new ManagedPaymentLinkEvidence('plink_a', $metadata),
                new ManagedPaymentLinkEvidence('plink_b', $metadata),
            ],
            [
                'plink_a' => [new ReconciliationCandidate('plink_a', $metadata)],
                'plink_b' => [new ReconciliationCandidate('plink_b', $metadata)],
            ],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);
        $tester = $this->tester(new StubReconciliationServiceFactory($this->service($stripe, $store)));

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_CONFLICT, $exit);
        self::assertStringContainsString('[CONFLICT]', $tester->getDisplay());
        self::assertSame([], $store->markPaidCalls);
    }

    public function testMaterialManualReviewOutcomeUsesStableExitCode(): void
    {
        $this->enableReconciliation();
        $metadata = $this->metadata();
        $stripe = new StubReconciliationStripeGateway(
            [new ManagedPaymentLinkEvidence('plink_orphan', $metadata)],
            ['plink_orphan' => [$this->successfulCandidate($metadata, 'plink_orphan')]],
        );
        $factory = new StubReconciliationServiceFactory($this->service(
            $stripe,
            new InMemoryReconciliationInvoiceStore(),
        ));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_MANUAL_REVIEW_REQUIRED, $exit);
        self::assertStringContainsString('[MANUAL_REVIEW_REQUIRED]', $tester->getDisplay());
        self::assertStringContainsString('invoice=INV-0042', $tester->getDisplay());
    }

    public function testInitialRetryableStripeFailureUsesRetryableExitCode(): void
    {
        $this->enableReconciliation();
        $stripe = new StubReconciliationStripeGateway(
            [],
            linkFailure: new ReconciliationGatewayException(
                OperatorResult::RETRYABLE,
                'Stripe Payment Link discovery failed.',
            ),
        );
        $factory = new StubReconciliationServiceFactory($this->service($stripe));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_RETRYABLE, $exit);
        self::assertStringContainsString('[RETRYABLE]', $tester->getDisplay());
    }

    public function testUnexpectedFactoryFailureUsesInternalErrorExitCodeWithoutLeakingException(): void
    {
        $this->enableReconciliation();
        $factory = new StubReconciliationServiceFactory(
            $this->service(new StubReconciliationStripeGateway([])),
            new RuntimeException('sensitive implementation detail'),
        );
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(ReconcileCommand::EXIT_INTERNAL_ERROR, $exit);
        self::assertStringContainsString('[INTERNAL_ERROR]', $tester->getDisplay());
        self::assertStringNotContainsString('sensitive implementation detail', $tester->getDisplay());
    }

    private function tester(StubReconciliationServiceFactory $factory): CommandTester
    {
        return new CommandTester(new ReconcileCommand(new EnvironmentConfigurationLoader(), $factory));
    }

    private function enableReconciliation(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=' . self::INSTANCE_ID);
        putenv('KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=' . 'rk_' . 'test_' . str_repeat('x', 24));
    }

    private function service(
        StubReconciliationStripeGateway $stripe,
        ?InMemoryReconciliationInvoiceStore $store = null,
    ): ReconciliationService {
        return new ReconciliationService(
            $stripe,
            $store ?? new InMemoryReconciliationInvoiceStore(),
            new ReconciliationPlanner(),
        );
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function successfulCandidate(
        InvoiceMetadata $metadata,
        string $paymentLinkId = 'plink_1',
    ): ReconciliationCandidate {
        return new ReconciliationCandidate(
            $paymentLinkId,
            $metadata,
            new CheckoutSessionEvidence(
                'cs_1',
                'complete',
                'paid',
                87510,
                'usd',
                $paymentLinkId,
                'pi_1',
                $metadata,
            ),
            new PaymentIntentEvidence(
                'pi_1',
                'succeeded',
                87510,
                87510,
                'usd',
                'ch_1',
                $metadata,
            ),
            new ChargeEvidence(
                'ch_1',
                true,
                true,
                false,
                false,
                87510,
                87510,
                0,
                'usd',
                'pi_1',
                1_788_541_200,
            ),
        );
    }
}
