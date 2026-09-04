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
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\StripePaymentLinkServiceFactory;
use KimaiStripeConnectorTests\Support\ScriptedStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

final class StripePaymentLinkHttpBoundaryTest extends TestCase
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

    public function testLockedStripeSdkReceivesExpectedCreateContract(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload([])),
            $this->response($this->paymentLinkPayload('plink_created', $metadata)),
        ]);
        ApiRequestor::setHttpClient($http);

        $outcome = $this->service()->ensure(false, $metadata);

        self::assertSame(OperatorResult::SUCCESS, $outcome->result());
        self::assertSame('https://buy.stripe.com/test_plink_created', $outcome->url());
        self::assertCount(2, $http->requests);
        self::assertSame('get', $http->requests[0]['method']);
        self::assertStringEndsWith('/v1/payment_links', $http->requests[0]['url']);
        self::assertSame(['limit' => 100], $http->requests[0]['params']);
        self::assertSame('post', $http->requests[1]['method']);
        self::assertStringEndsWith('/v1/payment_links', $http->requests[1]['url']);
        self::assertSame(['card'], $http->requests[1]['params']['payment_method_types']);
        self::assertSame([
            'completed_sessions' => [
                'limit' => 1,
            ],
        ], $http->requests[1]['params']['restrictions']);
        self::assertSame($metadata->toStripeMetadata(), $http->requests[1]['params']['metadata']);
        self::assertSame(
            $metadata->toStripeMetadata(),
            $http->requests[1]['params']['payment_intent_data']['metadata'],
        );
        self::assertTrue($this->hasIdempotencyHeader($http->requests[1]['headers']));
    }

    public function testTimeoutAfterPossibleCommitRediscoveriesWithoutDuplicatePost(): void
    {
        $metadata = $this->metadata();
        $http = new ScriptedStripeHttpClient([
            $this->response($this->listPayload([])),
            ApiConnectionException::factory('simulated timeout after remote commit'),
            $this->response($this->listPayload([
                $this->paymentLinkPayload('plink_recovered', $metadata),
            ])),
        ]);
        ApiRequestor::setHttpClient($http);

        $outcome = $this->service()->ensure(false, $metadata);

        self::assertSame(OperatorResult::NO_CHANGE, $outcome->result());
        self::assertSame('https://buy.stripe.com/test_plink_recovered', $outcome->url());
        self::assertCount(3, $http->requests);
        self::assertSame(1, count(array_filter(
            $http->requests,
            static fn (array $request): bool => $request['method'] === 'post',
        )));
    }

    private function service(): \KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkService
    {
        $factory = new StripePaymentLinkServiceFactory(new StripeClientFactory());

        return $factory->create(new StripeCredential(
            'rk_' . 'test_' . str_repeat('x', 24),
            CredentialSource::DIRECT,
        ));
    }

    private function metadata(): InvoiceMetadata
    {
        return new InvoiceMetadata(
            new KimaiInstanceId(self::INSTANCE_ID),
            'INV-0042',
            Money::fromDecimal('875.10', 'usd'),
        );
    }

    /**
     * @param list<array<string, mixed>> $links
     *
     * @return array<string, mixed>
     */
    private function listPayload(array $links): array
    {
        return [
            'object' => 'list',
            'data' => $links,
            'has_more' => false,
            'url' => '/v1/payment_links',
        ];
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

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{body: string, status: int, headers: array<string, string>}
     */
    private function response(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'status' => 200,
            'headers' => ['request-id' => 'req_test'],
        ];
    }

    /** @param array<int, string> $headers */
    private function hasIdempotencyHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Idempotency-Key: ')) {
                return true;
            }
        }

        return false;
    }
}
