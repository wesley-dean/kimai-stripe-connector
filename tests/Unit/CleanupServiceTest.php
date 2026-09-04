<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupPlanner;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupService;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ChargeEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\CheckoutSessionEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\PaymentIntentEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationInvoice;
use KimaiStripeConnectorTests\Support\InMemoryReconciliationInvoiceStore;
use KimaiStripeConnectorTests\Support\SequencedReconciliationInvoiceStore;
use KimaiStripeConnectorTests\Support\StubCleanupStripeGateway;
use KimaiStripeConnectorTests\Support\StubReconciliationStripeGateway;
use PHPUnit\Framework\TestCase;

final class CleanupServiceTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const NOW = 1_788_600_000;
    private const DAY = 86_400;

    public function testDryRunReportsEligibleWithoutStripeMutation(): void
    {
        $link = $this->link();
        $reader = new StubReconciliationStripeGateway([$link]);
        $writer = new StubCleanupStripeGateway();
        $service = $this->service($reader, new InMemoryReconciliationInvoiceStore(), $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, true, self::NOW);

        self::assertCount(1, $outcomes);
        self::assertSame(OperatorResult::SUCCESS, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->wouldChange());
        self::assertFalse($outcomes[0]->changed());
        self::assertSame(1, $reader->linkRequests);
        self::assertSame([], $writer->deactivateCalls);
        self::assertSame(['plink_1'], $reader->candidateRequests);
    }

    public function testApplyRevalidatesThenDeactivates(): void
    {
        $link = $this->link();
        $reader = new StubReconciliationStripeGateway([$link]);
        $writer = new StubCleanupStripeGateway();
        $service = $this->service($reader, new InMemoryReconciliationInvoiceStore(), $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::SUCCESS, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->changed());
        self::assertSame(2, $reader->linkRequests);
        self::assertSame(['plink_1', 'plink_1'], $reader->candidateRequests);
        self::assertSame(['plink_1'], $writer->deactivateCalls);
    }

    public function testMatchingKimaiInvoiceSkipsStripeChildEvidence(): void
    {
        $metadata = $this->metadata();
        $reader = new StubReconciliationStripeGateway([$this->link()]);
        $writer = new StubCleanupStripeGateway();
        $store = new InMemoryReconciliationInvoiceStore([
            'INV-0042' => new ReconciliationInvoice($metadata, false),
        ]);
        $service = $this->service($reader, $store, $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::NO_CHANGE, $outcomes[0]->result());
        self::assertSame(1, $reader->linkRequests);
        self::assertSame([], $reader->candidateRequests);
        self::assertSame([], $writer->deactivateCalls);
    }

    public function testKimaiInvoiceAppearingDuringRevalidationPreventsDeactivation(): void
    {
        $invoice = new ReconciliationInvoice($this->metadata(), false);
        $store = new SequencedReconciliationInvoiceStore([null, $invoice]);
        $reader = new StubReconciliationStripeGateway([$this->link()]);
        $writer = new StubCleanupStripeGateway();
        $service = $this->service($reader, $store, $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::NO_CHANGE, $outcomes[0]->result());
        self::assertSame(2, $store->lookupCount);
        self::assertSame(1, $reader->linkRequests);
        self::assertSame(['plink_1'], $reader->candidateRequests);
        self::assertSame([], $writer->deactivateCalls);
    }

    public function testCompetingPaymentLinkAppearingDuringRevalidationPreventsDeactivation(): void
    {
        $first = $this->link();
        $second = new ManagedPaymentLinkEvidence(
            'plink_2',
            $this->metadata(),
            self::NOW - (8 * self::DAY),
            true,
        );
        $reader = new StubReconciliationStripeGateway(
            [$first],
            linkSequences: [[$first], [$first, $second]],
        );
        $writer = new StubCleanupStripeGateway();
        $service = $this->service($reader, new InMemoryReconciliationInvoiceStore(), $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::CONFLICT, $outcomes[0]->result());
        self::assertSame(2, $reader->linkRequests);
        self::assertSame(['plink_1', 'plink_1', 'plink_2'], $reader->candidateRequests);
        self::assertSame([], $writer->deactivateCalls);
    }

    public function testPaymentAppearingDuringRevalidationPreventsDeactivation(): void
    {
        $link = $this->link();
        $reader = new StubReconciliationStripeGateway(
            [$link],
            candidateSequences: [
                'plink_1' => [[$this->candidate()], [$this->successfulCandidate()]],
            ],
        );
        $writer = new StubCleanupStripeGateway();
        $service = $this->service($reader, new InMemoryReconciliationInvoiceStore(), $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $outcomes[0]->result());
        self::assertSame(2, $reader->linkRequests);
        self::assertSame(['plink_1', 'plink_1'], $reader->candidateRequests);
        self::assertSame([], $writer->deactivateCalls);
    }

    public function testRetryableStripeMutationFailureRemainsRetryable(): void
    {
        $reader = new StubReconciliationStripeGateway([$this->link()]);
        $writer = new StubCleanupStripeGateway(
            failure: new CleanupGatewayException(OperatorResult::RETRYABLE, 'Simulated cleanup timeout.'),
        );
        $service = $this->service($reader, new InMemoryReconciliationInvoiceStore(), $writer);

        $outcomes = $service->cleanup($this->instanceId(), 7, false, self::NOW);

        self::assertSame(OperatorResult::RETRYABLE, $outcomes[0]->result());
        self::assertTrue($outcomes[0]->wouldChange());
        self::assertFalse($outcomes[0]->changed());
    }

    private function service(
        StubReconciliationStripeGateway $reader,
        InMemoryReconciliationInvoiceStore|SequencedReconciliationInvoiceStore $store,
        StubCleanupStripeGateway $writer,
    ): CleanupService {
        return new CleanupService($reader, $store, $writer, new CleanupPlanner());
    }

    private function link(): ManagedPaymentLinkEvidence
    {
        return new ManagedPaymentLinkEvidence(
            'plink_1',
            $this->metadata(),
            self::NOW - (8 * self::DAY),
            true,
        );
    }

    private function candidate(): ReconciliationCandidate
    {
        return new ReconciliationCandidate('plink_1', $this->metadata());
    }

    private function successfulCandidate(): ReconciliationCandidate
    {
        $metadata = $this->metadata();
        $session = new CheckoutSessionEvidence(
            'cs_1',
            'complete',
            'paid',
            87510,
            'usd',
            'plink_1',
            'pi_1',
            $metadata,
        );
        $intent = new PaymentIntentEvidence(
            'pi_1',
            'succeeded',
            87510,
            87510,
            'usd',
            'ch_1',
            $metadata,
        );
        $charge = new ChargeEvidence(
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
            self::NOW - self::DAY,
        );

        return new ReconciliationCandidate('plink_1', $metadata, $session, $intent, $charge);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            $this->instanceId(),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function instanceId(): KimaiInstanceId
    {
        return new KimaiInstanceId(self::INSTANCE_ID);
    }
}
