<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use App\Entity\Invoice;
use App\Invoice\InvoiceService;
use App\Repository\InvoiceRepository;
use DateTimeImmutable;
use DateTimeZone;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ChargeEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\KimaiReconciliationInvoiceStore;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPersistenceException;
use PHPUnit\Framework\TestCase;

final class KimaiReconciliationInvoiceStoreTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testLookupBuildsExactConnectorSnapshot(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $store = new KimaiReconciliationInvoiceStore($repository, new InvoiceService());

        $invoice = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');

        self::assertNotNull($invoice);
        self::assertFalse($invoice->isPaid());
        self::assertSame($this->metadata()->toStripeMetadata(), $invoice->metadata()->toStripeMetadata());
        self::assertSame([['invoiceNumber' => 'INV-0042']], $repository->findOneByCalls);
    }

    public function testMissingInvoiceReturnsNull(): void
    {
        $store = new KimaiReconciliationInvoiceStore(new InvoiceRepository(), new InvoiceService());

        self::assertNull($store->findByInvoiceNumber($this->instanceId(), 'INV-MISSING'));
    }

    public function testUnrepresentableKimaiFloatFailsClosed(): void
    {
        $entity = $this->invoice();
        $entity->setTotal(0.1 + 0.2);
        $store = new KimaiReconciliationInvoiceStore(
            new InvoiceRepository(['INV-0042' => $entity]),
            new InvoiceService(),
        );

        try {
            $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
            self::fail('Expected an unsafe Kimai float to require manual review.');
        } catch (ReconciliationPersistenceException $exception) {
            self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $exception->result());
        }
    }

    public function testPaidTransitionUsesStripeChargeDateInInvoiceTimezone(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $invoiceService = new InvoiceService();
        $store = new KimaiReconciliationInvoiceStore($repository, $invoiceService);
        $snapshot = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
        self::assertNotNull($snapshot);

        $changed = $store->markPaid($snapshot, $this->candidate($this->metadata(), $this->chargeTimestamp()));

        self::assertTrue($changed);
        self::assertTrue($entity->isPaid());
        self::assertSame('2026-09-04', $entity->getPaymentDate()?->format('Y-m-d'));
        self::assertSame('America/New_York', $entity->getPaymentDate()?->getTimezone()->getName());
        self::assertSame([$entity], $invoiceService->savedInvoices);
        self::assertCount(2, $repository->findOneByCalls);
    }

    public function testKimaiDriftBetweenPlanningAndPersistenceProducesConflict(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $invoiceService = new InvoiceService();
        $store = new KimaiReconciliationInvoiceStore($repository, $invoiceService);
        $snapshot = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
        self::assertNotNull($snapshot);
        $entity->setTotal(900.00);

        try {
            $store->markPaid($snapshot, $this->candidate($this->metadata(), $this->chargeTimestamp()));
            self::fail('Expected changed Kimai financial data to block persistence.');
        } catch (ReconciliationPersistenceException $exception) {
            self::assertSame(OperatorResult::CONFLICT, $exception->result());
            self::assertFalse($entity->isPaid());
            self::assertSame([], $invoiceService->savedInvoices);
        }
    }

    public function testConcurrentAlreadyPaidTransitionIsIdempotent(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $invoiceService = new InvoiceService();
        $store = new KimaiReconciliationInvoiceStore($repository, $invoiceService);
        $snapshot = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
        self::assertNotNull($snapshot);
        $entity->setIsPaid();

        $changed = $store->markPaid($snapshot, $this->candidate($this->metadata(), $this->chargeTimestamp()));

        self::assertFalse($changed);
        self::assertSame([], $invoiceService->savedInvoices);
    }

    public function testEvidenceMismatchNeverMutatesKimai(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $invoiceService = new InvoiceService();
        $store = new KimaiReconciliationInvoiceStore($repository, $invoiceService);
        $snapshot = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
        self::assertNotNull($snapshot);
        $otherMetadata = new InvoiceMetadata(
            $this->instanceId(),
            'INV-0042',
            Money::fromDecimal('999.00', 'usd'),
        );

        try {
            $store->markPaid($snapshot, $this->candidate($otherMetadata, $this->chargeTimestamp()));
            self::fail('Expected mismatched Stripe evidence to block persistence.');
        } catch (ReconciliationPersistenceException $exception) {
            self::assertSame(OperatorResult::CONFLICT, $exception->result());
            self::assertFalse($entity->isPaid());
            self::assertSame([], $invoiceService->savedInvoices);
        }
    }

    public function testMissingChargeTimestampFailsBeforeMutation(): void
    {
        $entity = $this->invoice();
        $repository = new InvoiceRepository(['INV-0042' => $entity]);
        $invoiceService = new InvoiceService();
        $store = new KimaiReconciliationInvoiceStore($repository, $invoiceService);
        $snapshot = $store->findByInvoiceNumber($this->instanceId(), 'INV-0042');
        self::assertNotNull($snapshot);

        try {
            $store->markPaid($snapshot, $this->candidate($this->metadata(), null));
            self::fail('Expected missing Charge timestamp to block persistence.');
        } catch (ReconciliationPersistenceException $exception) {
            self::assertSame(OperatorResult::INTERNAL_ERROR, $exception->result());
            self::assertFalse($entity->isPaid());
            self::assertSame([], $invoiceService->savedInvoices);
        }
    }

    private function invoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNumber('INV-0042');
        $invoice->setTotal(875.10);
        $invoice->setCurrency('USD');
        $invoice->setCreatedAt(new DateTimeImmutable(
            '2026-09-01T12:00:00',
            new DateTimeZone('America/New_York'),
        ));

        return $invoice;
    }

    private function instanceId(): KimaiInstanceId
    {
        return new KimaiInstanceId(self::INSTANCE_ID);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            $this->instanceId(),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    private function chargeTimestamp(): int
    {
        return (new DateTimeImmutable('2026-09-05T01:30:00+00:00'))->getTimestamp();
    }

    private function candidate(InvoiceMetadata $metadata, ?int $createdAt): ReconciliationCandidate
    {
        return new ReconciliationCandidate(
            'plink_1',
            $metadata,
            charge: new ChargeEvidence(
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
                $createdAt,
            ),
        );
    }
}
