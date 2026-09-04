<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Integration;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\StripeReconciliationGateway;
use KimaiStripeConnectorTests\Support\ScriptedStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

final class StripeReconciliationPaginationTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

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

    public function testPaymentLinkDiscoveryPaginatesWithLockedStripeSdk(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_1', $metadata),
            ], true)),
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_2', $metadata),
            ])),
        ]);
        ApiRequestor::setHttpClient($http);

        $links = $this->gateway()->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID));

        self::assertSame(['plink_1', 'plink_2'], array_map(
            static fn ($link): string => $link->id(),
            $links,
        ));
        self::assertCount(2, $http->requests);
        self::assertSame(100, $http->requests[0]['params']['limit'] ?? null);
        self::assertSame(100, $http->requests[1]['params']['limit'] ?? null);
        self::assertSame('plink_1', $http->requests[1]['params']['starting_after'] ?? null);
    }

    public function testCheckoutSessionDiscoveryPaginatesWithinOnePaymentLink(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload('/v1/payment_links', [
                $this->paymentLinkPayload('plink_1', $metadata),
            ])),
            $this->response($this->listPayload('/v1/checkout/sessions', [
                $this->sessionAttemptPayload('cs_1', $metadata),
            ], true)),
            $this->response($this->listPayload('/v1/checkout/sessions', [
                $this->sessionAttemptPayload('cs_2', $metadata),
            ])),
        ]);
        ApiRequestor::setHttpClient($http);
        $gateway = $this->gateway();
        $link = $gateway->listManagedPaymentLinks(new KimaiInstanceId(self::INSTANCE_ID))[0];

        $candidates = $gateway->listCandidates($link);

        self::assertSame(['cs_1', 'cs_2'], array_map(
            static fn ($candidate): ?string => $candidate->checkoutSession()?->id(),
            $candidates,
        ));
        self::assertCount(3, $http->requests);
        self::assertSame('plink_1', $http->requests[1]['params']['payment_link'] ?? null);
        self::assertSame(100, $http->requests[1]['params']['limit'] ?? null);
        self::assertSame('plink_1', $http->requests[2]['params']['payment_link'] ?? null);
        self::assertSame(100, $http->requests[2]['params']['limit'] ?? null);
        self::assertSame('cs_1', $http->requests[2]['params']['starting_after'] ?? null);
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

    /** @return array<string, mixed> */
    private function paymentLinkPayload(string $id, InvoiceMetadata $metadata): array
    {
        return [
            'id' => $id,
            'object' => 'payment_link',
            'active' => true,
            'url' => 'https://buy.stripe.com/test_' . $id,
            'metadata' => $metadata->toStripeMetadata(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionAttemptPayload(string $id, InvoiceMetadata $metadata): array
    {
        return [
            'id' => $id,
            'object' => 'checkout.session',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => 87510,
            'currency' => 'usd',
            'payment_link' => 'plink_1',
            'payment_intent' => null,
            'metadata' => $metadata->toStripeMetadata(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $data
     *
     * @return array<string, mixed>
     */
    private function listPayload(string $url, array $data, bool $hasMore = false): array
    {
        return [
            'object' => 'list',
            'data' => $data,
            'has_more' => $hasMore,
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
            'headers' => ['request-id' => 'req_reconciliation_pagination'],
        ];
    }
}
