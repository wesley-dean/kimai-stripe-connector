<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGateway;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkService;
use PHPUnit\Framework\TestCase;

final class PaymentLinkServiceTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const OTHER_INSTANCE_ID = '0190a7b3-ec4f-7a31-8a0e-31fa10b7f6d8';

    public function testPreviewDoesNotReadOrMutateStripe(): void
    {
        $gateway = new FakePaymentLinkGateway([
            new PaymentLinkGatewayException('must not be called'),
        ]);

        $outcome = (new PaymentLinkService($gateway))->ensure(true, $this->metadata());

        self::assertSame(OperatorResult::NO_CHANGE, $outcome->result());
        self::assertSame(0, $gateway->listCalls);
        self::assertSame(0, $gateway->createCalls);
    }

    public function testExactExistingLinkIsReusedWithoutCreate(): void
    {
        $candidate = $this->candidate('plink_existing', $this->metadata()->toStripeMetadata());
        $gateway = new FakePaymentLinkGateway([[$candidate]]);

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::NO_CHANGE, $outcome->result());
        self::assertSame($candidate->url(), $outcome->url());
        self::assertSame(0, $gateway->createCalls);
    }

    public function testMissingLinkIsCreatedOnce(): void
    {
        $created = $this->candidate('plink_created', $this->metadata()->toStripeMetadata());
        $gateway = new FakePaymentLinkGateway([[]], $created);

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::SUCCESS, $outcome->result());
        self::assertSame($created->url(), $outcome->url());
        self::assertSame(1, $gateway->createCalls);
        self::assertNotNull($gateway->lastCreateRequest);
        self::assertArrayHasKey('idempotency_key', $gateway->lastCreateRequest->requestOptions());
    }

    public function testFinancialConflictDoesNotCreateAnotherLink(): void
    {
        $metadata = $this->metadata()->toStripeMetadata();
        $metadata['invoice_amount'] = '87511';
        $gateway = new FakePaymentLinkGateway([
            [$this->candidate('plink_conflict', $metadata)],
        ]);

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::CONFLICT, $outcome->result());
        self::assertSame(0, $gateway->createCalls);
    }

    public function testUncertainCreateCommitRecoversExactLinkWithoutSecondCreate(): void
    {
        $recovered = $this->candidate('plink_recovered', $this->metadata()->toStripeMetadata());
        $gateway = new FakePaymentLinkGateway(
            [[], [$recovered]],
            createException: new PaymentLinkGatewayException('timeout', true),
        );

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::NO_CHANGE, $outcome->result());
        self::assertSame($recovered->url(), $outcome->url());
        self::assertSame(2, $gateway->listCalls);
        self::assertSame(1, $gateway->createCalls);
    }

    public function testUncertainCreateCommitNeverBlindlyCreatesAgainWhenRediscoveryIsEmpty(): void
    {
        $gateway = new FakePaymentLinkGateway(
            [[], []],
            createException: new PaymentLinkGatewayException('timeout', true),
        );

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::RETRYABLE, $outcome->result());
        self::assertSame(2, $gateway->listCalls);
        self::assertSame(1, $gateway->createCalls);
    }

    public function testDefiniteCreateFailureIsRetryableWithoutSecondCreate(): void
    {
        $gateway = new FakePaymentLinkGateway(
            [[]],
            createException: new PaymentLinkGatewayException('request rejected', false),
        );

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::RETRYABLE, $outcome->result());
        self::assertSame(1, $gateway->listCalls);
        self::assertSame(1, $gateway->createCalls);
    }

    public function testDiscoveryFailureIsRetryableAndDoesNotCreate(): void
    {
        $gateway = new FakePaymentLinkGateway([
            new PaymentLinkGatewayException('network unavailable'),
        ]);

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::RETRYABLE, $outcome->result());
        self::assertSame(0, $gateway->createCalls);
    }

    public function testUnexpectedCreatedObjectRequiresManualReview(): void
    {
        $metadata = $this->metadata()->toStripeMetadata();
        $metadata['kimai_instance'] = self::OTHER_INSTANCE_ID;
        $created = $this->candidate('plink_wrong_identity', $metadata);
        $gateway = new FakePaymentLinkGateway([[]], $created);

        $outcome = (new PaymentLinkService($gateway))->ensure(false, $this->metadata());

        self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $outcome->result());
        self::assertSame(1, $gateway->createCalls);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function candidate(string $id, array $metadata): PaymentLinkCandidate
    {
        return PaymentLinkCandidate::fromStripePayload([
            'id' => $id,
            'url' => 'https://buy.stripe.com/test_' . $id,
            'active' => true,
            'metadata' => $metadata,
        ]);
    }
}

final class FakePaymentLinkGateway implements PaymentLinkGateway
{
    public int $listCalls = 0;
    public int $createCalls = 0;
    public ?PaymentLinkCreateRequest $lastCreateRequest = null;

    /**
     * @param list<list<PaymentLinkCandidate>|PaymentLinkGatewayException> $listResults
     */
    public function __construct(
        private array $listResults,
        private ?PaymentLinkCandidate $createCandidate = null,
        private ?PaymentLinkGatewayException $createException = null,
    ) {
    }

    /** @return list<PaymentLinkCandidate> */
    public function listAll(): array
    {
        $result = $this->listResults[$this->listCalls] ?? [];
        ++$this->listCalls;

        if ($result instanceof PaymentLinkGatewayException) {
            throw $result;
        }

        return $result;
    }

    public function create(PaymentLinkCreateRequest $request): PaymentLinkCandidate
    {
        ++$this->createCalls;
        $this->lastCreateRequest = $request;

        if ($this->createException !== null) {
            throw $this->createException;
        }

        if ($this->createCandidate === null) {
            throw new PaymentLinkGatewayException('Fake gateway has no create result configured.');
        }

        return $this->createCandidate;
    }
}
