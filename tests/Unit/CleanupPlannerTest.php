<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupPlanner;
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
use PHPUnit\Framework\TestCase;

final class CleanupPlannerTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const NOW = 1_788_600_000;
    private const DAY = 86_400;

    public function testMatchingKimaiInvoiceAlwaysRetainsLink(): void
    {
        $metadata = $this->metadata();
        $plan = (new CleanupPlanner())->plan(
            new ReconciliationInvoice($metadata, false),
            [$this->link()],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    public function testOldActiveLinkWithoutCheckoutSessionsIsEligible(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(createdAt: self::NOW - (8 * self::DAY))],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::SUCCESS, $plan->result());
        self::assertTrue($plan->shouldDeactivate());
    }

    public function testExactGraceBoundaryIsEligible(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(createdAt: self::NOW - (7 * self::DAY))],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertTrue($plan->shouldDeactivate());
    }

    public function testLinkInsideGracePeriodIsRetained(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(createdAt: self::NOW - (6 * self::DAY))],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    public function testMissingCreationTimestampRequiresManualReview(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(createdAt: null)],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testMissingActiveStateRequiresManualReview(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(active: null)],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testFutureCreationTimestampRequiresManualReview(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(createdAt: self::NOW + 1)],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testAlreadyInactiveUnpaidOrphanIsNoChange(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(active: false)],
            [$this->candidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    public function testMultiplePaymentLinksForSameInvoiceConflict(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link(id: 'plink_1'), $this->link(id: 'plink_2')],
            [$this->candidate('plink_1'), $this->candidate('plink_2')],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    public function testSuccessfulPaymentOnOrphanRequiresManualReview(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link()],
            [$this->successfulCandidate()],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    public function testOpenCheckoutAttemptIsNotConclusiveUnpaidEvidence(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link()],
            [$this->candidate(session: $this->session(status: 'open'))],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testExpiredUnpaidCheckoutWithoutIntentIsEligible(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link()],
            [$this->candidate(session: $this->session())],
            self::NOW,
            7,
        );

        self::assertTrue($plan->shouldDeactivate());
    }

    public function testExpiredFailedPaymentIntentWithoutChargeIsEligible(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link()],
            [$this->candidate(
                session: $this->session(paymentIntentId: 'pi_1'),
                intent: $this->intent(),
            )],
            self::NOW,
            7,
        );

        self::assertTrue($plan->shouldDeactivate());
    }

    public function testProcessingPaymentIntentRequiresManualReview(): void
    {
        $plan = (new CleanupPlanner())->plan(
            null,
            [$this->link()],
            [$this->candidate(
                session: $this->session(paymentIntentId: 'pi_1'),
                intent: $this->intent(status: 'processing'),
            )],
            self::NOW,
            7,
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testEmptyCandidateEvidenceFailsClosed(): void
    {
        $plan = (new CleanupPlanner())->plan(null, [$this->link()], [], self::NOW, 7);

        self::assertSame(OperatorResult::INTERNAL_ERROR, $plan->result());
        self::assertFalse($plan->shouldDeactivate());
    }

    private function link(
        string $id = 'plink_1',
        ?int $createdAt = self::NOW - (8 * self::DAY),
        ?bool $active = true,
    ): ManagedPaymentLinkEvidence {
        return new ManagedPaymentLinkEvidence($id, $this->metadata(), $createdAt, $active);
    }

    private function candidate(
        string $linkId = 'plink_1',
        ?CheckoutSessionEvidence $session = null,
        ?PaymentIntentEvidence $intent = null,
        ?ChargeEvidence $charge = null,
    ): ReconciliationCandidate {
        return new ReconciliationCandidate(
            $linkId,
            $this->metadata(),
            $session,
            $intent,
            $charge,
        );
    }

    private function successfulCandidate(): ReconciliationCandidate
    {
        return $this->candidate(
            session: $this->session(
                status: 'complete',
                paymentStatus: 'paid',
                paymentIntentId: 'pi_1',
            ),
            intent: $this->intent(
                status: 'succeeded',
                received: 87510,
                latestChargeId: 'ch_1',
            ),
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
                self::NOW - self::DAY,
            ),
        );
    }

    private function session(
        string $status = 'expired',
        string $paymentStatus = 'unpaid',
        ?string $paymentIntentId = null,
    ): CheckoutSessionEvidence {
        return new CheckoutSessionEvidence(
            'cs_1',
            $status,
            $paymentStatus,
            87510,
            'usd',
            'plink_1',
            $paymentIntentId,
            $this->metadata(),
        );
    }

    private function intent(
        string $status = 'requires_payment_method',
        int $received = 0,
        ?string $latestChargeId = null,
    ): PaymentIntentEvidence {
        return new PaymentIntentEvidence(
            'pi_1',
            $status,
            87510,
            $received,
            'usd',
            $latestChargeId,
            $this->metadata(),
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
}
