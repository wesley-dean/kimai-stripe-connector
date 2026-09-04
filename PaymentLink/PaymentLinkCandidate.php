<?php

/**
 * @file
 * @brief Represents the safe subset of a Stripe Payment Link needed for planning.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use InvalidArgumentException;

/**
 * @brief Validated local representation of a discovered Stripe Payment Link.
 */
final readonly class PaymentLinkCandidate
{
    /**
     * @param array<string, mixed> $metadata Stripe metadata attached to the Payment Link.
     */
    public function __construct(
        private string $id,
        private string $url,
        private bool $active,
        private array $metadata,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Payment Link ID must not be blank.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Payment Link URL must be a valid HTTPS URL.');
        }
    }

    /**
     * @brief Build a candidate from a Stripe-like response payload.
     *
     * @param array<string, mixed> $payload Stripe Payment Link response fields.
     *
     * @throws InvalidArgumentException When required response fields are malformed.
     */
    public static function fromStripePayload(array $payload): self
    {
        if (!isset($payload['id']) || !is_string($payload['id'])) {
            throw new InvalidArgumentException('Stripe Payment Link response is missing a valid ID.');
        }

        if (!isset($payload['url']) || !is_string($payload['url'])) {
            throw new InvalidArgumentException('Stripe Payment Link response is missing a valid URL.');
        }

        if (!array_key_exists('active', $payload) || !is_bool($payload['active'])) {
            throw new InvalidArgumentException('Stripe Payment Link response is missing a valid active flag.');
        }

        if (!array_key_exists('metadata', $payload) || !is_array($payload['metadata'])) {
            throw new InvalidArgumentException('Stripe Payment Link response is missing valid metadata.');
        }

        foreach (array_keys($payload['metadata']) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Stripe Payment Link metadata keys must be strings.');
            }
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'];

        return new self($payload['id'], $payload['url'], $payload['active'], $metadata);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
