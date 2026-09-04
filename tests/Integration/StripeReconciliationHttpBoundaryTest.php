<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Integration;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\StripeReconciliationGateway;
use KimaiStripeConnectorTests\Support\ScriptedStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

final class StripeReconciliationHttpBoundaryTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const OTHER_INSTANCE_ID = '0190a7b3-ec4f-7a31-8a0e-31fa10b7f6d8';
    private const CHARGE_CREATED_AT = 1_788_541_200;

    private ClientInterface $originalHttpClient;
    private bool $originalTelemetry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalHttpClient = ApiRequestor::httpClient();
        $this->originalTelemetry = Stripe::getEnableTelemetry();
        Stripe::setEnableTelemetry(false);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->originalHttpClient);
        Stripe::setEnableTelemetry($this->originalTelemetry);

        parent::tearDown();
    }

    public function testLockedSdkBuildsCompleteReadOnlyEvidenceChain(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_foreign', []),
                $this->paymentLinkPayload('plink_1', $metadata->toStripeMetadata()),
            ])),
            $this->response($this->listPayload('/v1/checkout/sessions', [
                $this->sessionPayload($metadata),
            ])),
            $this->response($this->paymentIntentPayload($metadata)),
            $this->response($this->chargePayload()),
        ]);
        ApiRequestor::setHttpClient($http);
        $gateway = $this->gateway();

        $links = $gateway->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID));

        self::assertCount(1, $links);
        self::assertSame('plink_1', $links[0]->id());
        self::assertSame($metadata->toStripeMetadata(), $links[0]->metadata()->toStripeMetadata());

        $candidates = $gateway->listCandidates($links[0]);

        self::assertCount(1, $candidates);
        self::assertSame('plink_1', $candidates[0]->paymentLinkId());
        self::assertSame('cs_1', $candidates[0]->checkoutSession()?->id());
        self::assertSame('pi_1', $candidates[0]->paymentIntent()?->id());
        self::assertSame('ch_1', $candidates[0]->charge()?->id());
        self::assertSame(87510, $candidates[0]->charge()?->amountCaptured());
        self::assertSame(self::CHARGE_CREATED_AT, $candidates[0]->charge()?->createdAt());

        self::assertCount(4, $http->requests);
        self::assertStringEndsWith('/v1/payment_links', $http->requests[0]['url']);
        self::assertSame(['limit' => 100], $http->requests[0]['params']);
        self::assertStringEndsWith('/v1/checkout/sessions', $http->requests[1]['url']);
        self::assertSame(
            ['payment_link' => 'plink_1', 'limit' => 100],
            $http->requests[1]['params'],
        );
        self::assertStringEndsWith('/v1/payment_intents/pi_1', $http->requests[2]['url']);
        self::assertStringEndsWith('/v1/charges/ch_1', $http->requests[3]['url']);
        self::assertSame([], array_filter(
            $http->requests,
            static fn (array $request): bool => $request['method'] !== 'get',
        ));
    }

    public function testManagedLinkWithoutCheckoutSessionsRemainsVisible(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_1', $metadata->toStripeMetadata()),
            ])),
            $this->response($this->listPayload('/v1/checkout/sessions', [])),
        ]);
        ApiRequestor::setHttpClient($http);
        $gateway = $this->gateway();

        $link = $gateway->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID))[0];
        $candidates = $gateway->listCandidates($link);

        self::assertCount(1, $candidates);
        self::assertNull($candidates[0]->checkoutSession());
        self::assertNull($candidates[0]->paymentIntent());
        self::assertNull($candidates[0]->charge());
    }

    public function testMalformedManagedLinkForThisInstanceRequiresManualReview(): void
    {
        $metadata = $this->metadata()->toStripeMetadata();
        unset($metadata['invoice_amount']);
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_bad', $metadata),
            ])),
        ]);
        ApiRequestor::setHttpClient($http);

        try {
            $this->gateway()->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID));
            self::fail('Expected malformed managed evidence to stop automatic reconciliation.');
        } catch (ReconciliationGatewayException $exception) {
            self::assertSame(OperatorResult::MANUAL_REVIEW_REQUIRED, $exception->result());
        }
    }

    public function testMalformedManagedLinkForDifferentInstanceIsIgnoredBeforeFullParsing(): void
    {
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_other', [
                    'integration' => InvoiceMetadata::INTEGRATION,
                    'kimai_instance' => self::OTHER_INSTANCE_ID,
                ]),
            ])),
        ]);
        ApiRequestor::setHttpClient($http);

        $links = $this->gateway()->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID));

        self::assertSame([], $links);
    }

    public function testStripeConnectionFailureIsRetryable(): void
    {
        $http = new ScriptedStripeHttpClient([
            ApiConnectionException::factory('simulated read timeout'),
        ]);
        ApiRequestor::setHttpClient($http);

        try {
            $this->gateway()->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID));
            self::fail('Expected Stripe connection failure.');
        } catch (ReconciliationGatewayException $exception) {
            self::assertSame(OperatorResult::RETRYABLE, $exception->result());
        }
    }

    private function gateway(): StripeReconciliationGateway
    {
        $client = (new StripeClientFactory())->create(new StripeCredential(
            'rk_' . 'test_' . str_repeat('x', 24),
            CredentialSource::DIRECT,
        ));

        return new StripeReconciliationGateway($client);
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
    private function paymentLinkPayload(string $id, array $metadata): array
    {
        return [
            'id' => $id,
            'object' => 'payment_link',
            'active' => true,
            'url' => 'https://buy.stripe.com/test_' . $id,
            'metadata' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionPayload(InvoiceMetadata $metadata): array
    {
        return [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 87510,
            'currency' => 'usd',
            'payment_link' => 'plink_1',
            'payment_intent' => 'pi_1',
            'metadata' => $metadata->toStripeMetadata(),
        ];
    }

    /** @return array<string, mixed> */
    private function paymentIntentPayload(InvoiceMetadata $metadata): array
    {
        return [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 87510,
            'amount_received' => 87510,
            'currency' => 'usd',
            'latest_charge' => 'ch_1',
            'metadata' => $metadata->toStripeMetadata(),
        ];
    }

    /** @return array<string, mixed> */
    private function chargePayload(): array
    {
        return [
            'id' => 'ch_1',
            'object' => 'charge',
            'paid' => true,
            'captured' => true,
            'refunded' => false,
            'disputed' => false,
            'amount' => 87510,
            'amount_captured' => 87510,
            'amount_refunded' => 0,
            'currency' => 'usd',
            'payment_intent' => 'pi_1',
            'created' => self::CHARGE_CREATED_AT,
        ];
    }

    /**
     * @param list<array<string, mixed>> $data
     *
     * @return array<string, mixed>
     */
    private function listPayload(string $url, array $data): array
    {
        return [
            'object' => 'list',
            'data' => $data,
            'has_more' => false,
            'url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{body: string, status: int, headers: array<string, string>}
     */
    private function response(array $payload): array
    {
        return [
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 200,
            'headers' => ['request-id' => 'req_reconciliation'],
        ];
    }
}
