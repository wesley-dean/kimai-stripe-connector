<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ChargeEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\CheckoutSessionEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\PaymentIntentEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationPlanner;
use PHPUnit\Framework\TestCase;

final class ReconciliationPlannerTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const OTHER_INSTANCE_ID = '0190a7b3-ec4f-7a31-8a0e-31fa10b7f6d8';

    public function testNoLinkProducesNoChange(): void
    {
        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), []);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testLinkWithoutSessionProducesNoChange(): void
    {
        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$this->candidate()]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testOpenCheckoutProducesNoChange(): void
    {
        $candidate = $this->candidate(session: $this->session(status: 'open', paymentIntentId: null));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testCompleteButUnpaidCheckoutProducesNoChange(): void
    {
        $candidate = $this->candidate(session: $this->session(paymentStatus: 'unpaid', paymentIntentId: null));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testProcessingPaymentIntentProducesNoChange(): void
    {
        $candidate = $this->candidate(
            session: $this->session(),
            intent: $this->intent(status: 'processing', received: 0, latestChargeId: null),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testFailedPaymentIntentProducesNoChange(): void
    {
        $candidate = $this->candidate(
            session: $this->session(),
            intent: $this->intent(status: 'requires_payment_method', received: 0, latestChargeId: null),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testSameInvoiceWithWrongAmountProducesConflict(): void
    {
        $candidate = $this->candidate(linkMetadata: $this->metadata(amount: '900.00'));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testSameInvoiceWithWrongCurrencyProducesConflict(): void
    {
        $candidate = $this->candidate(linkMetadata: $this->metadata(currency: 'eur'));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
    }

    public function testWrongInstanceIsIgnored(): void
    {
        $candidate = $this->candidate(linkMetadata: $this->metadata(instanceId: self::OTHER_INSTANCE_ID));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertNull($plan->candidate());
    }

    public function testWrongInvoiceIsIgnored(): void
    {
        $candidate = $this->candidate(linkMetadata: $this->metadata(invoiceNumber: 'INV-9999'));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testPartialPaymentProducesConflict(): void
    {
        $candidate = $this->candidate(
            session: $this->session(),
            intent: $this->intent(received: 40000, latestChargeId: null),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
    }

    public function testUncapturedChargeProducesNoChange(): void
    {
        $candidate = $this->successfulCandidate(
            charge: $this->charge(captured: false, amountCaptured: 0),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testPartiallyCapturedChargeProducesConflict(): void
    {
        $candidate = $this->successfulCandidate(charge: $this->charge(amountCaptured: 40000));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testRefundedChargeRequiresManualReview(): void
    {
        $candidate = $this->successfulCandidate(
            charge: $this->charge(refunded: true, amountRefunded: 87510),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testPartialRefundRequiresManualReview(): void
    {
        $candidate = $this->successfulCandidate(charge: $this->charge(amountRefunded: 1000));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testDisputedChargeRequiresManualReview(): void
    {
        $candidate = $this->successfulCandidate(charge: $this->charge(disputed: true));

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testExactSuccessfulEvidenceMarksPaid(): void
    {
        $candidate = $this->successfulCandidate();

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::SUCCESS, $plan->result());
        self::assertTrue($plan->shouldMarkPaid());
        self::assertSame($candidate, $plan->candidate());
    }

    public function testOpenAttemptAndSuccessfulAttemptOnSameLinkMarksPaid(): void
    {
        $openAttempt = $this->candidate(
            session: $this->session(id: 'cs_open', status: 'open', paymentStatus: 'unpaid', paymentIntentId: null),
        );
        $successfulAttempt = $this->successfulCandidate();

        $plan = (new ReconciliationPlanner())->plan(
            false,
            $this->metadata(),
            [$openAttempt, $successfulAttempt],
        );

        self::assertSame(OperatorResult::SUCCESS, $plan->result());
        self::assertTrue($plan->shouldMarkPaid());
        self::assertSame($successfulAttempt, $plan->candidate());
    }

    public function testMultipleSuccessfulAttemptsOnSameLinkRequireManualReview(): void
    {
        $secondSession = $this->session(id: 'cs_2', paymentIntentId: 'pi_2');
        $secondIntent = $this->intent(id: 'pi_2', latestChargeId: 'ch_2');
        $secondCharge = $this->charge(id: 'ch_2', paymentIntentId: 'pi_2');

        $plan = (new ReconciliationPlanner())->plan(
            false,
            $this->metadata(),
            [
                $this->successfulCandidate(),
                $this->successfulCandidate($secondSession, $secondIntent, $secondCharge),
            ],
        );

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testRerunAfterPaidIsIdempotentNoChange(): void
    {
        $plan = (new ReconciliationPlanner())->plan(true, $this->metadata(), [$this->successfulCandidate()]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testAlreadyManuallyPaidWithoutStripeEvidencePreservesState(): void
    {
        $plan = (new ReconciliationPlanner())->plan(true, $this->metadata(), []);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
    }

    public function testLaterRefundAfterPaidReportsOnly(): void
    {
        $candidate = $this->successfulCandidate(
            charge: $this->charge(refunded: true, amountRefunded: 87510),
        );

        $plan = (new ReconciliationPlanner())->plan(true, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    public function testLaterDisputeAfterPaidReportsOnly(): void
    {
        $candidate = $this->successfulCandidate(charge: $this->charge(disputed: true));

        $plan = (new ReconciliationPlanner())->plan(true, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testMultipleExactPaymentLinksProduceConflict(): void
    {
        $plan = (new ReconciliationPlanner())->plan(
            false,
            $this->metadata(),
            [$this->candidate(linkId: 'plink_1'), $this->candidate(linkId: 'plink_2')],
        );

        self::assertSame(OperatorResult::CONFLICT, $plan->result());
    }

    public function testChildMetadataMismatchRequiresManualReview(): void
    {
        $candidate = $this->candidate(
            session: $this->session(metadata: $this->metadata(invoiceNumber: 'INV-9999')),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testContradictoryStripeRelationshipsRequireManualReview(): void
    {
        $candidate = $this->successfulCandidate(
            charge: $this->charge(paymentIntentId: 'pi_other'),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $plan->result());
    }

    public function testUnknownStripeStatusFailsClosedAsNoChange(): void
    {
        $candidate = $this->successfulCandidate(
            intent: $this->intent(status: 'future_unknown_state'),
        );

        $plan = (new ReconciliationPlanner())->plan(false, $this->metadata(), [$candidate]);

        self::assertSame(OperatorResult::NO_CHANGE, $plan->result());
        self::assertFalse($plan->shouldMarkPaid());
    }

    private function successfulCandidate(
        ?CheckoutSessionEvidence $session = null,
        ?PaymentIntentEvidence $intent = null,
        ?ChargeEvidence $charge = null,
    ): ReconciliationCandidate {
        return $this->candidate(
            session: $session ?? $this->session(),
            intent: $intent ?? $this->intent(),
            charge: $charge ?? $this->charge(),
        );
    }

    private function candidate(
        string $linkId = 'plink_1',
        ?InvoiceMetadata $linkMetadata = null,
        ?CheckoutSessionEvidence $session = null,
        ?PaymentIntentEvidence $intent = null,
        ?ChargeEvidence $charge = null,
    ): ReconciliationCandidate {
        return new ReconciliationCandidate(
            $linkId,
            $linkMetadata ?? $this->metadata(),
            $session,
            $intent,
            $charge,
        );
    }

    private function session(
        string $id = 'cs_1',
        string $status = 'complete',
        string $paymentStatus = 'paid',
        int $amount = 87510,
        string $currency = 'usd',
        string $paymentLinkId = 'plink_1',
        ?string $paymentIntentId = 'pi_1',
        ?InvoiceMetadata $metadata = null,
    ): CheckoutSessionEvidence {
        return new CheckoutSessionEvidence(
            $id,
            $status,
            $paymentStatus,
            $amount,
            $currency,
            $paymentLinkId,
            $paymentIntentId,
            $metadata ?? $this->metadata(),
        );
    }

    private function intent(
        string $status = 'succeeded',
        int $amount = 87510,
        int $received = 87510,
        string $currency = 'usd',
        ?string $latestChargeId = 'ch_1',
        ?InvoiceMetadata $metadata = null,
        string $id = 'pi_1',
    ): PaymentIntentEvidence {
        return new PaymentIntentEvidence(
            $id,
            $status,
            $amount,
            $received,
            $currency,
            $latestChargeId,
            $metadata ?? $this->metadata(),
        );
    }

    private function charge(
        string $id = 'ch_1',
        bool $paid = true,
        bool $captured = true,
        bool $refunded = false,
        bool $disputed = false,
        int $amount = 87510,
        int $amountCaptured = 87510,
        int $amountRefunded = 0,
        string $currency = 'usd',
        string $paymentIntentId = 'pi_1',
    ): ChargeEvidence {
        return new ChargeEvidence(
            $id,
            $paid,
            $captured,
            $refunded,
            $disputed,
            $amount,
            $amountCaptured,
            $amountRefunded,
            $currency,
            $paymentIntentId,
        );
    }

    private function metadata(
        string $invoiceNumber = 'INV-0042',
        string $amount = '875.10',
        string $currency = 'usd',
        string $instanceId = self::INSTANCE_ID,
    ): InvoiceMetadata {
        return new InvoiceMetadata(
            new KimaiInstanceId($instanceId),
            $invoiceNumber,
            Money::fromDecimal($amount, $currency),
        );
    }
}
