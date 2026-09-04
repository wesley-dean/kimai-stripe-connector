<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

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
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPersistenceException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPlanner;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationService;
use KimaiStripeConnectorTests\Support\InMemoryReconciliationInvoiceStore;
use KimaiStripeConnectorTests\Support\StubReconciliationStripeGateway;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testNoManagedLinksProducesNoInvoiceOutcomes(): void
    {
        $stripe = new StubReconciliationStripeGateway([]);
        $store = new InMemoryReconciliationInvoiceStore();

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame([], $outcomes);
    }

    public function testExactSuccessfulEvidenceMarksInvoicePaid(): void
    {
        $metadata = $this->metadata('INV-0042');
        $link = new ManagedPaymentLinkEvidence('plink_1', $metadata);
        $stripe = new StubReconciliationStripeGateway(
            [$link],
            ['plink_1' => [$this->successfulCandidate('plink_1', $metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertCount(1, $outcomes);
        self::assertSame(OperatorResult::SUCCESS, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->wouldChange());
        self::assertTrue($outcomes[0]->changed());
        self::assertSame(['INV-0042'], $store->markPaidCalls);
        self::assertTrue($store->findByInvoiceNumber($this->instanceId(), 'INV-0042')?->isPaid());
    }

    public function testDryRunReportsWouldChangeWithoutPersistence(): void
    {
        $metadata = $this->metadata('INV-0042');
        $link = new ManagedPaymentLinkEvidence('plink_1', $metadata);
        $stripe = new StubReconciliationStripeGateway(
            [$link],
            ['plink_1' => [$this->successfulCandidate('plink_1', $metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId(), true);

        self::assertSame(OperatorResult::SUCCESS, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->wouldChange());
        self::assertFalse($outcomes[0]->changed());
        self::assertSame([], $store->markPaidCalls);
        self::assertFalse($store->findByInvoiceNumber($this->instanceId(), 'INV-0042')?->isPaid());
    }

    public function testPersistenceFailureNeverReportsFalseSuccess(): void
    {
        $metadata = $this->metadata('INV-0042');
        $link = new ManagedPaymentLinkEvidence('plink_1', $metadata);
        $stripe = new StubReconciliationStripeGateway(
            [$link],
            ['plink_1' => [$this->successfulCandidate('plink_1', $metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore(
            ['INV-0042' => new ReconciliationInvoice($metadata, false)],
            markFailures: [
                'INV-0042' => new ReconciliationPersistenceException(
                    OperatorResult::RETRYABLE,
                    'Kimai persistence temporarily failed.',
                ),
            ],
        );

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame(OperatorResult::RETRYABLE, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->wouldChange());
        self::assertFalse($outcomes[0]->changed());
        self::assertFalse($store->findByInvoiceNumber($this->instanceId(), 'INV-0042')?->isPaid());
    }

    public function testSuccessfulPaidOrphanRequiresManualReview(): void
    {
        $metadata = $this->metadata('INV-ORPHAN');
        $link = new ManagedPaymentLinkEvidence('plink_orphan', $metadata);
        $stripe = new StubReconciliationStripeGateway(
            [$link],
            ['plink_orphan' => [$this->successfulCandidate('plink_orphan', $metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore();

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $outcomes[0]->result());
        self::assertFalse($outcomes[0]->wouldChange());
        self::assertFalse($outcomes[0]->changed());
        self::assertSame([], $store->markPaidCalls);
    }

    public function testUnpaidOrphanProducesNoChange(): void
    {
        $metadata = $this->metadata('INV-ORPHAN');
        $link = new ManagedPaymentLinkEvidence('plink_orphan', $metadata);
        $stripe = new StubReconciliationStripeGateway([$link]);
        $store = new InMemoryReconciliationInvoiceStore();

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame(OperatorResult::NO_CHANGE, $outcomes[0]->result());
        self::assertFalse($outcomes[0]->changed());
    }

    public function testInvoiceEvidenceFailureDoesNotBlockIndependentInvoice(): void
    {
        $metadataA = $this->metadata('INV-A');
        $metadataB = $this->metadata('INV-B');
        $linkA = new ManagedPaymentLinkEvidence('plink_a', $metadataA);
        $linkB = new ManagedPaymentLinkEvidence('plink_b', $metadataB);
        $stripe = new StubReconciliationStripeGateway(
            [$linkB, $linkA],
            ['plink_b' => [$this->successfulCandidate('plink_b', $metadataB)]],
            ['plink_a' => new ReconciliationGatewayException(
                OperatorResult::RETRYABLE,
                'Stripe read for INV-A failed.',
            )],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-A' => new ReconciliationInvoice($metadataA, false),
            'INV-B' => new ReconciliationInvoice($metadataB, false),
        ]);

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertCount(2, $outcomes);
        self::assertSame('INV-A', $outcomes[0]->invoiceNumber());
        self::assertSame(OperatorResult::RETRYABLE, $outcomes[0]->result());
        self::assertSame('INV-B', $outcomes[1]->invoiceNumber());
        self::assertSame(OperatorResult::SUCCESS, $outcomes[1]->result());
        self::assertSame(['INV-B'], $store->markPaidCalls);
    }

    public function testMultipleExactPaymentLinksForOneInvoiceProduceConflictWithoutPersistence(): void
    {
        $metadata = $this->metadata('INV-0042');
        $linkA = new ManagedPaymentLinkEvidence('plink_a', $metadata);
        $linkB = new ManagedPaymentLinkEvidence('plink_b', $metadata);
        $stripe = new StubReconciliationStripeGateway([$linkA, $linkB]);
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame(OperatorResult::CONFLICT, $outcomes[0]->result());
        self::assertSame([], $store->markPaidCalls);
    }

    public function testAlreadyPaidInvoiceIsPreserved(): void
    {
        $metadata = $this->metadata('INV-0042');
        $link = new ManagedPaymentLinkEvidence('plink_1', $metadata);
        $stripe = new StubReconciliationStripeGateway(
            [$link],
            ['plink_1' => [$this->successfulCandidate('plink_1', $metadata)]],
        );
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, true),
        ]);

        $outcomes = $this->service($stripe, $store)->reconcile($this->instanceId());

        self::assertSame(OperatorResult::NO_CHANGE, $outcomes[0]->result());
        self::assertSame([], $store->markPaidCalls);
        self::assertTrue($store->findByInvoiceNumber($this->instanceId(), 'INV-0042')?->isPaid());
    }

    public function testInitialPaymentLinkDiscoveryFailureStopsRunSafely(): void
    {
        $stripe = new StubReconciliationStripeGateway(
            [],
            linkFailure: new ReconciliationGatewayException(
                OperatorResult::RETRYABLE,
                'Stripe Payment Link discovery failed.',
            ),
        );

        $this->expectException(ReconciliationGatewayException::class);
        $this->service($stripe, new InMemoryReconciliationInvoiceStore())->reconcile($this->instanceId());
    }

    private function service(
        StubReconciliationStripeGateway $stripe,
        InMemoryReconciliationInvoiceStore $store,
    ): ReconciliationService {
        return new ReconciliationService($stripe, $store, new ReconciliationPlanner());
    }

    private function instanceId(): KimaiInstanceId
    {
        return new KimaiInstanceId(self::INSTANCE_ID);
    }

    private function metadata(string $invoiceNumber): InvoiceMetadata
    {
        return new InvoiceMetadata(
            $this->instanceId(),
            $invoiceNumber,
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function successfulCandidate(
        string $paymentLinkId,
        InvoiceMetadata $metadata,
    ): ReconciliationCandidate {
        return new ReconciliationCandidate(
            $paymentLinkId,
            $metadata,
            new CheckoutSessionEvidence(
                'cs_' . $paymentLinkId,
                'complete',
                'paid',
                87510,
                'usd',
                $paymentLinkId,
                'pi_' . $paymentLinkId,
                $metadata,
            ),
            new PaymentIntentEvidence(
                'pi_' . $paymentLinkId,
                'succeeded',
                87510,
                87510,
                'usd',
                'ch_' . $paymentLinkId,
                $metadata,
            ),
            new ChargeEvidence(
                'ch_' . $paymentLinkId,
                true,
                true,
                false,
                false,
                87510,
                87510,
                0,
                'usd',
                'pi_' . $paymentLinkId,
            ),
        );
    }
}
