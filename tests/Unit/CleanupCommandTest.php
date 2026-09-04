<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupCommand;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupPlanner;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupService;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ChargeEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\CheckoutSessionEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\PaymentIntentEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use KimaiStripeConnectorTests\Support\InMemoryReconciliationInvoiceStore;
use KimaiStripeConnectorTests\Support\StubCleanupServiceFactory;
use KimaiStripeConnectorTests\Support\StubCleanupStripeGateway;
use KimaiStripeConnectorTests\Support\StubReconciliationStripeGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupCommandTest extends TestCase
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
        $factory = new StubCleanupServiceFactory($this->service(new StubReconciliationStripeGateway([])));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('[NO_CHANGE]', $tester->getDisplay());
        self::assertSame([], $factory->credentials);
    }

    public function testActiveConfigurationWithoutCredentialReturnsConfigurationError(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=' . self::INSTANCE_ID);
        $factory = new StubCleanupServiceFactory($this->service(new StubReconciliationStripeGateway([])));
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_CONFIGURATION_ERROR, $exit);
        self::assertStringContainsString('[CONFIGURATION_ERROR]', $tester->getDisplay());
        self::assertSame([], $factory->credentials);
    }

    public function testDryRunReportsEligibleOrphanWithoutMutation(): void
    {
        $this->enableCleanup();
        putenv('KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS=1');
        $link = $this->link();
        $reader = new StubReconciliationStripeGateway([$link]);
        $writer = new StubCleanupStripeGateway();
        $factory = new StubCleanupServiceFactory($this->service($reader, $writer));
        $tester = $this->tester($factory);

        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(CleanupCommand::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('[SUCCESS]', $tester->getDisplay());
        self::assertStringContainsString('invoice=INV-0042', $tester->getDisplay());
        self::assertStringContainsString('payment_link=plink_1', $tester->getDisplay());
        self::assertStringContainsString('would_change=yes changed=no', $tester->getDisplay());
        self::assertSame([], $writer->deactivateCalls);
        self::assertCount(1, $factory->credentials);
    }

    public function testEligibleOrphanIsDeactivated(): void
    {
        $this->enableCleanup();
        putenv('KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS=1');
        $reader = new StubReconciliationStripeGateway([$this->link()]);
        $writer = new StubCleanupStripeGateway();
        $tester = $this->tester(new StubCleanupServiceFactory($this->service($reader, $writer)));

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_SUCCESS, $exit);
        self::assertStringContainsString('[SUCCESS]', $tester->getDisplay());
        self::assertStringContainsString('would_change=yes changed=yes', $tester->getDisplay());
        self::assertSame(['plink_1'], $writer->deactivateCalls);
    }

    public function testMultipleLinksUseConflictExitCode(): void
    {
        $this->enableCleanup();
        $metadata = $this->metadata();
        $reader = new StubReconciliationStripeGateway([
            new ManagedPaymentLinkEvidence('plink_a', $metadata, 1, true),
            new ManagedPaymentLinkEvidence('plink_b', $metadata, 1, true),
        ]);
        $tester = $this->tester(new StubCleanupServiceFactory($this->service($reader)));

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_CONFLICT, $exit);
        self::assertStringContainsString('[CONFLICT]', $tester->getDisplay());
        self::assertStringContainsString('payment_link=multiple', $tester->getDisplay());
    }

    public function testPaidOrphanUsesManualReviewExitCode(): void
    {
        $this->enableCleanup();
        $metadata = $this->metadata();
        $link = $this->link();
        $reader = new StubReconciliationStripeGateway(
            [$link],
            ['plink_1' => [$this->successfulCandidate($metadata)]],
        );
        $tester = $this->tester(new StubCleanupServiceFactory($this->service($reader)));

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_MANUAL_REVIEW_REQUIRED, $exit);
        self::assertStringContainsString('[MANUAL_REVIEW_REQUIRED]', $tester->getDisplay());
        self::assertStringContainsString('invoice=INV-0042', $tester->getDisplay());
    }

    public function testInitialRetryableStripeFailureUsesRetryableExitCode(): void
    {
        $this->enableCleanup();
        $reader = new StubReconciliationStripeGateway(
            [],
            linkFailure: new ReconciliationGatewayException(
                OperatorResult::RETRYABLE,
                'Stripe Payment Link discovery failed.',
            ),
        );
        $tester = $this->tester(new StubCleanupServiceFactory($this->service($reader)));

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_RETRYABLE, $exit);
        self::assertStringContainsString('[RETRYABLE]', $tester->getDisplay());
    }

    public function testRetryableDeactivationFailureUsesRetryableExitCode(): void
    {
        $this->enableCleanup();
        $reader = new StubReconciliationStripeGateway([$this->link()]);
        $writer = new StubCleanupStripeGateway(
            failure: new CleanupGatewayException(OperatorResult::RETRYABLE, 'Stripe cleanup timed out.'),
        );
        $tester = $this->tester(new StubCleanupServiceFactory($this->service($reader, $writer)));

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_RETRYABLE, $exit);
        self::assertStringContainsString('[RETRYABLE]', $tester->getDisplay());
    }

    public function testUnexpectedFactoryFailureUsesInternalErrorWithoutLeakingException(): void
    {
        $this->enableCleanup();
        $factory = new StubCleanupServiceFactory(
            $this->service(new StubReconciliationStripeGateway([])),
            new RuntimeException('sensitive implementation detail'),
        );
        $tester = $this->tester($factory);

        $exit = $tester->execute([]);

        self::assertSame(CleanupCommand::EXIT_INTERNAL_ERROR, $exit);
        self::assertStringContainsString('[INTERNAL_ERROR]', $tester->getDisplay());
        self::assertStringNotContainsString('sensitive implementation detail', $tester->getDisplay());
    }

    private function tester(StubCleanupServiceFactory $factory): CommandTester
    {
        return new CommandTester(new CleanupCommand(new EnvironmentConfigurationLoader(), $factory));
    }

    private function enableCleanup(): void
    {
        putenv('KIMAI_STRIPE_CONNECTOR_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED=true');
        putenv('KIMAI_STRIPE_CONNECTOR_INSTANCE_ID=' . self::INSTANCE_ID);
        putenv('KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY=' . 'rk_' . 'test_' . str_repeat('x', 24));
    }

    private function service(
        StubReconciliationStripeGateway $reader,
        ?StubCleanupStripeGateway $writer = null,
    ): CleanupService {
        return new CleanupService(
            $reader,
            new InMemoryReconciliationInvoiceStore(),
            $writer ?? new StubCleanupStripeGateway(),
            new CleanupPlanner(),
        );
    }

    private function link(): ManagedPaymentLinkEvidence
    {
        return new ManagedPaymentLinkEvidence('plink_1', $this->metadata(), 1, true);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function successfulCandidate(InvoiceMetadata $metadata): ReconciliationCandidate
    {
        return new ReconciliationCandidate(
            'plink_1',
            $metadata,
            new CheckoutSessionEvidence(
                'cs_1',
                'complete',
                'paid',
                87510,
                'usd',
                'plink_1',
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
