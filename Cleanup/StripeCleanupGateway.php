<?php

/**
 * @file
 * @brief Deactivates only a revalidated connector-managed Stripe Payment Link.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\MetadataException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Throwable;

/**
 * @brief Stripe cleanup adapter with last-moment Payment Link revalidation.
 *
 * @details
 * Cleanup never sends an update based only on stale discovery evidence.  The
 * adapter retrieves the target immediately before mutation and requires exact
 * connector metadata plus the same creation timestamp.  Setting active=false is
 * monotonic and idempotent; a retry after an uncertain commit will observe the
 * already-inactive object on the next run.
 */
final readonly class StripeCleanupGateway implements CleanupStripeGateway
{
    public function __construct(private StripeClient $client)
    {
    }

    public function deactivatePaymentLink(ManagedPaymentLinkEvidence $expected): bool
    {
        $expectedCreatedAt = $expected->createdAt();
        if ($expectedCreatedAt === null) {
            throw $this->manualReview('Cleanup cannot mutate a Payment Link without proven creation-time evidence.');
        }

        try {
            $current = $this->client->paymentLinks->retrieve($expected->id());
            /** @var array<string, mixed> $currentPayload */
            $currentPayload = $current->toArray();

            $currentId = $this->requiredString($currentPayload, 'id', 'Payment Link');
            if ($currentId !== $expected->id()) {
                throw new CleanupGatewayException(
                    OperatorResult::INTERNAL_ERROR,
                    'Stripe returned a different Payment Link than the cleanup target.',
                );
            }

            $currentMetadata = $this->parseMetadata(
                $this->requiredMetadata($currentPayload),
                'Payment Link',
            );
            if ($currentMetadata->toStripeMetadata() !== $expected->metadata()->toStripeMetadata()) {
                throw new CleanupGatewayException(
                    OperatorResult::CONFLICT,
                    'Stripe Payment Link metadata changed before cleanup deactivation.',
                );
            }

            if ($this->requiredInt($currentPayload, 'created', 'Payment Link') !== $expectedCreatedAt) {
                throw new CleanupGatewayException(
                    OperatorResult::CONFLICT,
                    'Stripe Payment Link creation evidence changed before cleanup deactivation.',
                );
            }

            if (!$this->requiredBool($currentPayload, 'active', 'Payment Link')) {
                return false;
            }

            $updated = $this->client->paymentLinks->update($expected->id(), ['active' => false]);
            /** @var array<string, mixed> $updatedPayload */
            $updatedPayload = $updated->toArray();

            if ($this->requiredString($updatedPayload, 'id', 'Payment Link') !== $expected->id()) {
                throw new CleanupGatewayException(
                    OperatorResult::INTERNAL_ERROR,
                    'Stripe cleanup response did not identify the requested Payment Link.',
                );
            }

            if ($this->requiredBool($updatedPayload, 'active', 'Payment Link')) {
                throw new CleanupGatewayException(
                    OperatorResult::INTERNAL_ERROR,
                    'Stripe cleanup response did not confirm Payment Link deactivation.',
                );
            }

            return true;
        } catch (CleanupGatewayException $exception) {
            throw $exception;
        } catch (ApiErrorException $exception) {
            throw $this->apiFailure('Stripe Payment Link deactivation failed.', $exception);
        } catch (UnexpectedValueException $exception) {
            throw new CleanupGatewayException(
                OperatorResult::INTERNAL_ERROR,
                'Stripe cleanup returned an unusable SDK response.',
                $exception,
            );
        } catch (MetadataException $exception) {
            throw $this->manualReview('Stripe Payment Link metadata is invalid during cleanup revalidation.', $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requiredMetadata(array $payload): array
    {
        if (!array_key_exists('metadata', $payload) || !is_array($payload['metadata'])) {
            throw $this->manualReview('Stripe Payment Link metadata is missing or malformed.');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
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

    private function apiFailure(string $message, ApiErrorException $exception): CleanupGatewayException
    {
        $status = $exception->getHttpStatus();
        $result = match (true) {
            $status === 401 || $status === 403 => OperatorResult::CONFIGURATION_ERROR,
            $status === null || $status === 408 || $status === 409 || $status === 429 || $status >= 500
                => OperatorResult::RETRYABLE,
            default => OperatorResult::INTERNAL_ERROR,
        };

        return new CleanupGatewayException($result, $message, $exception);
    }

    private function manualReview(string $message, ?Throwable $previous = null): CleanupGatewayException
    {
        return new CleanupGatewayException(
            OperatorResult::MANUAL_REVIEW_REQUIRED,
            $message,
            $previous,
        );
    }
}
