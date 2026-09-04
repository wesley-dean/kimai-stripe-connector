<?php

/**
 * @file
 * @brief Adapts the locked Stripe PHP SDK Payment Link service to the connector boundary.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Service\PaymentLinkService;
use Stripe\StripeClient;

/**
 * @brief Stripe SDK implementation of PaymentLinkGateway.
 */
final readonly class StripePaymentLinkGateway implements PaymentLinkGateway
{
    public function __construct(private PaymentLinkService $service)
    {
    }

    /**
     * @brief Construct the gateway from the project-configured Stripe client.
     */
    public static function fromClient(StripeClient $client): self
    {
        return new self($client->paymentLinks);
    }

    /** @return list<PaymentLinkCandidate> */
    public function listAll(): array
    {
        try {
            $collection = $this->service->all(['limit' => 100]);
            $candidates = [];

            foreach ($collection->autoPagingIterator() as $paymentLink) {
                $candidates[] = PaymentLinkCandidate::fromStripePayload($paymentLink->toArray());
            }

            return $candidates;
        } catch (ApiErrorException|UnexpectedValueException|InvalidArgumentException $exception) {
            throw new PaymentLinkGatewayException(
                'Stripe Payment Link discovery failed.',
                false,
                $exception,
            );
        }
    }

    public function create(PaymentLinkCreateRequest $request): PaymentLinkCandidate
    {
        try {
            $paymentLink = $this->service->create(
                $request->parameters(),
                $request->requestOptions(),
            );

            return PaymentLinkCandidate::fromStripePayload($paymentLink->toArray());
        } catch (ApiErrorException $exception) {
            $status = $exception->getHttpStatus();
            $commitMayHaveOccurred = $status === null || $status >= 500;

            throw new PaymentLinkGatewayException(
                'Stripe Payment Link creation failed.',
                $commitMayHaveOccurred,
                $exception,
            );
        } catch (UnexpectedValueException|InvalidArgumentException $exception) {
            throw new PaymentLinkGatewayException(
                'Stripe Payment Link creation returned an unusable response.',
                true,
                $exception,
            );
        }
    }
}
