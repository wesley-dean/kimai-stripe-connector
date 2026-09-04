<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Integration;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\StripeCleanupGateway;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiStripeConnectorTests\Support\ScriptedStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

final class StripeCleanupHttpBoundaryTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';
    private const CREATED_AT = 1_788_000_000;

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

    public function testLockedSdkRetrievesThenDeactivatesExactPaymentLink(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->paymentLinkPayload($metadata, true)),
            $this->response($this->paymentLinkPayload($metadata, false)),
        ]);
        ApiRequestor::setHttpClient($http);

        $changed = $this->gateway()->deactivatePaymentLink($this->evidence($metadata));

        self::assertTrue($changed);
        self::assertCount(2, $http->requests);
        self::assertSame('get', $http->requests[0]['method']);
        self::assertStringEndsWith('/v1/payment_links/plink_1', $http->requests[0]['url']);
        self::assertSame('post', $http->requests[1]['method']);
        self::assertStringEndsWith('/v1/payment_links/plink_1', $http->requests[1]['url']);
        self::assertSame(['active' => false], $http->requests[1]['params']);
    }

    public function testAlreadyInactivePaymentLinkIsIdempotentNoChange(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->paymentLinkPayload($metadata, false)),
        ]);
        ApiRequestor::setHttpClient($http);

        $changed = $this->gateway()->deactivatePaymentLink($this->evidence($metadata));

        self::assertFalse($changed);
        self::assertCount(1, $http->requests);
        self::assertSame('get', $http->requests[0]['method']);
    }

    public function testMetadataDriftStopsDeactivationAsConflict(): void
    {
        $expected = $this->metadata();
        $changedMetadata = new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('900.00', 'usd'),
        );
        $http = new ScriptedStripeHttpClient([
            $this->response($this->paymentLinkPayload($changedMetadata, true)),
        ]);
        ApiRequestor::setHttpClient($http);

        try {
            $this->gateway()->deactivatePaymentLink($this->evidence($expected));
            self::fail('Expected cleanup metadata drift to fail closed.');
        } catch (CleanupGatewayException $exception) {
            self::assertSame(OperatorResult::CONFLICT, $exception->result());
        }

        self::assertCount(1, $http->requests);
    }

    public function testUncertainStripeUpdateFailureIsRetryable(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->paymentLinkPayload($metadata, true)),
            ApiConnectionException::factory('simulated cleanup timeout after possible commit'),
        ]);
        ApiRequestor::setHttpClient($http);

        try {
            $this->gateway()->deactivatePaymentLink($this->evidence($metadata));
            self::fail('Expected cleanup update timeout.');
        } catch (CleanupGatewayException $exception) {
            self::assertSame(OperatorResult::RETRYABLE, $exception->result());
        }

        self::assertCount(2, $http->requests);
        self::assertSame('post', $http->requests[1]['method']);
    }

    private function gateway(): StripeCleanupGateway
    {
        $client = (new StripeClientFactory())->create(new StripeCredential(
            'rk_' . 'test_' . str_repeat('x', 24),
            CredentialSource::DIRECT,
        ));

        return new StripeCleanupGateway($client);
    }

    private function evidence(InvoiceMetadata $metadata): ManagedPaymentLinkEvidence
    {
        return new ManagedPaymentLinkEvidence('plink_1', $metadata, self::CREATED_AT, true);
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    /** @return array<string, mixed> */
    private function paymentLinkPayload(InvoiceMetadata $metadata, bool $active): array
    {
        return [
            'id' => 'plink_1',
            'object' => 'payment_link',
            'active' => $active,
            'created' => self::CREATED_AT,
            'url' => 'https://buy.stripe.com/test_plink_1',
            'metadata' => $metadata->toStripeMetadata(),
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
            'headers' => ['request-id' => 'req_cleanup'],
        ];
    }
}
