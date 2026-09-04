<?php

/**
 * @file
 * @brief Reads and validates the Stripe object chain used for reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\MetadataException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Throwable;

/**
 * @brief Read-only reconciliation adapter for the locked Stripe PHP SDK.
 *
 * @details
 * Discovery deliberately lives in the Reconciliation capability rather than
 * depending on PaymentLink runtime services.  This preserves the architectural
 * rule that reconciliation can operate when automatic Payment Link generation is
 * disabled, provided connector metadata already exists in Stripe.
 */
final readonly class StripeReconciliationGateway implements ReconciliationStripeGateway
{
    public function __construct(private StripeClient $client)
    {
    }

    /** @return list<ManagedPaymentLinkEvidence> */
    public function listManagedPaymentLinks(KimaiInstanceId $instanceId): array
    {
        try {
            $collection = $this->client->paymentLinks->all(['limit' => 100]);
            $links = [];

            foreach ($collection->autoPagingIterator() as $paymentLink) {
                /** @var array<string, mixed> $payload */
                $payload = $paymentLink->toArray();
                $metadata = $this->optionalMetadata($payload);

                if (($metadata['integration'] ?? null) !== InvoiceMetadata::INTEGRATION) {
                    continue;
                }

                $metadataInstance = $metadata['kimai_instance'] ?? null;
                if (!is_string($metadataInstance) || trim($metadataInstance) === '') {
                    throw $this->manualReview(
                        'Connector-managed Payment Link metadata cannot be scoped to a Kimai instance.',
                    );
                }

                if ($metadataInstance !== $instanceId->value()) {
                    continue;
                }

                $links[] = new ManagedPaymentLinkEvidence(
                    $this->requiredString($payload, 'id', 'Payment Link'),
                    $this->parseMetadata($metadata, 'Payment Link'),
                );
            }

            return $links;
        } catch (ReconciliationGatewayException $exception) {
            throw $exception;
        } catch (ApiErrorException $exception) {
            throw $this->apiFailure('Stripe Payment Link discovery failed.', $exception);
        } catch (UnexpectedValueException $exception) {
            throw new ReconciliationGatewayException(
                OperatorResult::INTERNAL_ERROR,
                'Stripe Payment Link discovery returned an unusable SDK response.',
                $exception,
            );
        } catch (InvalidArgumentException $exception) {
            throw $this->manualReview('Connector-managed Payment Link evidence is malformed.', $exception);
        }
    }

    /** @return list<ReconciliationCandidate> */
    public function listCandidates(ManagedPaymentLinkEvidence $paymentLink): array
    {
        try {
            $collection = $this->client->checkout->sessions->all([
                'payment_link' => $paymentLink->id(),
                'limit' => 100,
            ]);
            $candidates = [];

            foreach ($collection->autoPagingIterator() as $session) {
                /** @var array<string, mixed> $sessionPayload */
                $sessionPayload = $session->toArray();
                $sessionEvidence = $this->sessionEvidence($sessionPayload);
                $intentEvidence = null;
                $chargeEvidence = null;

                if ($sessionEvidence->paymentIntentId() !== null) {
                    $paymentIntent = $this->client->paymentIntents->retrieve($sessionEvidence->paymentIntentId());
                    /** @var array<string, mixed> $intentPayload */
                    $intentPayload = $paymentIntent->toArray();
                    $intentEvidence = $this->paymentIntentEvidence($intentPayload);

                    if ($intentEvidence->latestChargeId() !== null) {
                        $charge = $this->client->charges->retrieve($intentEvidence->latestChargeId());
                        /** @var array<string, mixed> $chargePayload */
                        $chargePayload = $charge->toArray();
                        $chargeEvidence = $this->chargeEvidence($chargePayload);
                    }
                }

                $candidates[] = new ReconciliationCandidate(
                    $paymentLink->id(),
                    $paymentLink->metadata(),
                    $sessionEvidence,
                    $intentEvidence,
                    $chargeEvidence,
                );
            }

            if ($candidates === []) {
                $candidates[] = new ReconciliationCandidate(
                    $paymentLink->id(),
                    $paymentLink->metadata(),
                );
            }

            return $candidates;
        } catch (ReconciliationGatewayException $exception) {
            throw $exception;
        } catch (ApiErrorException $exception) {
            throw $this->apiFailure('Stripe reconciliation evidence discovery failed.', $exception);
        } catch (UnexpectedValueException $exception) {
            throw new ReconciliationGatewayException(
                OperatorResult::INTERNAL_ERROR,
                'Stripe reconciliation evidence returned an unusable SDK response.',
                $exception,
            );
        } catch (InvalidArgumentException $exception) {
            throw $this->manualReview('Stripe reconciliation evidence is malformed.', $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function sessionEvidence(array $payload): CheckoutSessionEvidence
    {
        return new CheckoutSessionEvidence(
            $this->requiredString($payload, 'id', 'Checkout Session'),
            $this->requiredString($payload, 'status', 'Checkout Session'),
            $this->requiredString($payload, 'payment_status', 'Checkout Session'),
            $this->requiredInt($payload, 'amount_total', 'Checkout Session'),
            $this->requiredString($payload, 'currency', 'Checkout Session'),
            $this->requiredObjectId($payload, 'payment_link', 'Checkout Session'),
            $this->optionalObjectId($payload, 'payment_intent', 'Checkout Session'),
            $this->parseMetadata($this->requiredMetadata($payload, 'Checkout Session'), 'Checkout Session'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function paymentIntentEvidence(array $payload): PaymentIntentEvidence
    {
        return new PaymentIntentEvidence(
            $this->requiredString($payload, 'id', 'PaymentIntent'),
            $this->requiredString($payload, 'status', 'PaymentIntent'),
            $this->requiredInt($payload, 'amount', 'PaymentIntent'),
            $this->requiredInt($payload, 'amount_received', 'PaymentIntent'),
            $this->requiredString($payload, 'currency', 'PaymentIntent'),
            $this->optionalObjectId($payload, 'latest_charge', 'PaymentIntent'),
            $this->parseMetadata($this->requiredMetadata($payload, 'PaymentIntent'), 'PaymentIntent'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function chargeEvidence(array $payload): ChargeEvidence
    {
        return new ChargeEvidence(
            $this->requiredString($payload, 'id', 'Charge'),
            $this->requiredBool($payload, 'paid', 'Charge'),
            $this->requiredBool($payload, 'captured', 'Charge'),
            $this->requiredBool($payload, 'refunded', 'Charge'),
            $this->requiredBool($payload, 'disputed', 'Charge'),
            $this->requiredInt($payload, 'amount', 'Charge'),
            $this->requiredInt($payload, 'amount_captured', 'Charge'),
            $this->requiredInt($payload, 'amount_refunded', 'Charge'),
            $this->requiredString($payload, 'currency', 'Charge'),
            $this->requiredObjectId($payload, 'payment_intent', 'Charge'),
            $this->requiredInt($payload, 'created', 'Charge'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function optionalMetadata(array $payload): array
    {
        if (!array_key_exists('metadata', $payload)) {
            return [];
        }

        if (!is_array($payload['metadata'])) {
            throw $this->manualReview('Stripe metadata has an unexpected shape.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];

        return $metadata;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requiredMetadata(array $payload, string $context): array
    {
        if (!array_key_exists('metadata', $payload) || !is_array($payload['metadata'])) {
            throw $this->manualReview(sprintf('%s metadata is missing or malformed.', $context));
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function parseMetadata(array $metadata, string $context): InvoiceMetadata
    {
        try {
            return InvoiceMetadata::fromStripeMetadata($metadata);
        } catch (MetadataException $exception) {
            throw $this->manualReview(sprintf('%s connector metadata is invalid.', $context), $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field, string $context): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw $this->manualReview(sprintf('%s field "%s" is missing or malformed.', $context, $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredInt(array $payload, string $field, string $context): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value)) {
            throw $this->manualReview(sprintf('%s field "%s" is missing or malformed.', $context, $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredBool(array $payload, string $field, string $context): bool
    {
        $value = $payload[$field] ?? null;
        if (!is_bool($value)) {
            throw $this->manualReview(sprintf('%s field "%s" is missing or malformed.', $context, $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredObjectId(array $payload, string $field, string $context): string
    {
        $id = $this->objectId($payload[$field] ?? null);
        if ($id === null) {
            throw $this->manualReview(sprintf('%s field "%s" has no usable object ID.', $context, $field));
        }

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function optionalObjectId(array $payload, string $field, string $context): ?string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        $id = $this->objectId($payload[$field]);
        if ($id === null) {
            throw $this->manualReview(sprintf('%s field "%s" has no usable object ID.', $context, $field));
        }

        return $id;
    }

    private function objectId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? null;
            if (is_string($id) && trim($id) !== '') {
                return $id;
            }
        }

        return null;
    }

    private function apiFailure(string $message, ApiErrorException $exception): ReconciliationGatewayException
    {
        $status = $exception->getHttpStatus();
        $result = match (true) {
            $status === 401 || $status === 403 => OperatorResult::CONFIGURATION_ERROR,
            $status === null || $status === 408 || $status === 409 || $status === 429 || $status >= 500
                => OperatorResult::RETRYABLE,
            default => OperatorResult::INTERNAL_ERROR,
        };

        return new ReconciliationGatewayException($result, $message, $exception);
    }

    private function manualReview(string $message, ?Throwable $previous = null): ReconciliationGatewayException
    {
        return new ReconciliationGatewayException(
            OperatorResult::MANUAL_REVIEW_REQUIRED,
            $message,
            $previous,
        );
    }
}
