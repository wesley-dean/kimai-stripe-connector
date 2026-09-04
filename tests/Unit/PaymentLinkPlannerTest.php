<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkAction;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkPlanner;
use PHPUnit\Framework\TestCase;

final class PaymentLinkPlannerTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const OTHER_INSTANCE_ID = '0190a7b3-ec4f-7a31-8a0e-31fa10b7f6d8';

    public function testPreviewProducesNoMutationPlan(): void
    {
        $plan = (new PaymentLinkPlanner())->plan(true, $this->expectedMetadata(), []);

        self::assertSame(PaymentLinkAction::PREVIEW, $plan->action());
        self::assertNull($plan->candidate());
    }

    public function testExactActiveLinkIsReused(): void
    {
        $candidate = $this->candidate('plink_exact', $this->expectedMetadata()->toStripeMetadata());

        $plan = (new PaymentLinkPlanner())->plan(false, $this->expectedMetadata(), [$candidate]);

        self::assertSame(PaymentLinkAction::REUSE, $plan->action());
        self::assertSame($candidate, $plan->candidate());
    }

    public function testMissingLinkProducesCreatePlan(): void
    {
        $plan = (new PaymentLinkPlanner())->plan(false, $this->expectedMetadata(), []);

        self::assertSame(PaymentLinkAction::CREATE, $plan->action());
    }

    public function testAmountMismatchFailsClosedAsConflict(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();
        $metadata['invoice_amount'] = '87511';

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [$this->candidate('plink_amount_conflict', $metadata)],
        );

        self::assertSame(PaymentLinkAction::CONFLICT, $plan->action());
    }

    public function testCurrencyMismatchFailsClosedAsConflict(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();
        $metadata['invoice_currency'] = 'eur';

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [$this->candidate('plink_currency_conflict', $metadata)],
        );

        self::assertSame(PaymentLinkAction::CONFLICT, $plan->action());
    }

    public function testWrongInstanceIsIgnored(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();
        $metadata['kimai_instance'] = self::OTHER_INSTANCE_ID;

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [$this->candidate('plink_other_instance', $metadata)],
        );

        self::assertSame(PaymentLinkAction::CREATE, $plan->action());
    }

    public function testWrongInvoiceIsIgnored(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();
        $metadata['invoice_number'] = 'INV-9999';

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [$this->candidate('plink_other_invoice', $metadata)],
        );

        self::assertSame(PaymentLinkAction::CREATE, $plan->action());
    }

    public function testMultipleExactMatchesRequireManualReview(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [
                $this->candidate('plink_one', $metadata),
                $this->candidate('plink_two', $metadata),
            ],
        );

        self::assertSame(PaymentLinkAction::MANUAL_REVIEW_REQUIRED, $plan->action());
    }

    public function testUnknownMetadataVersionForSameLogicalInvoiceRequiresManualReview(): void
    {
        $metadata = $this->expectedMetadata()->toStripeMetadata();
        $metadata['integration_version'] = '2';

        $plan = (new PaymentLinkPlanner())->plan(
            false,
            $this->expectedMetadata(),
            [$this->candidate('plink_unknown_version', $metadata)],
        );

        self::assertSame(PaymentLinkAction::MANUAL_REVIEW_REQUIRED, $plan->action());
    }

    public function testInactiveExactLinkRequiresManualReview(): void
    {
        $candidate = $this->candidate(
            'plink_inactive',
            $this->expectedMetadata()->toStripeMetadata(),
            false,
        );

        $plan = (new PaymentLinkPlanner())->plan(false, $this->expectedMetadata(), [$candidate]);

        self::assertSame(PaymentLinkAction::MANUAL_REVIEW_REQUIRED, $plan->action());
    }

    public function testMalformedStripePayloadFailsClosedBeforePlanning(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentLinkCandidate::fromStripePayload([
            'id' => 'plink_bad',
            'url' => 'not-a-url',
            'active' => true,
            'metadata' => [],
        ]);
    }

    private function expectedMetadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function candidate(string $id, array $metadata, bool $active = true): PaymentLinkCandidate
    {
        return PaymentLinkCandidate::fromStripePayload([
            'id' => $id,
            'url' => 'https://buy.stripe.com/test_' . $id,
            'active' => $active,
            'metadata' => $metadata,
        ]);
    }
}
