<?php

/**
 * @file
 * @brief Defines the version-1 Stripe metadata correlation contract.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;

/**
 * @brief Exact versioned identity shared across connector-managed Stripe objects.
 */
final readonly class InvoiceMetadata
{
    public const INTEGRATION = 'kimai-stripe-connector';
    public const VERSION = '1';

    /**
     * @brief Construct version-1 invoice correlation metadata.
     *
     * @param KimaiInstanceId $instanceId Stable Kimai deployment identity.
     * @param string $invoiceNumber Exact Kimai invoice number.
     * @param Money $money Exact invoice amount/currency.
     *
     * @throws MetadataException When the invoice number cannot fit the Stripe metadata contract.
     */
    public function __construct(
        private KimaiInstanceId $instanceId,
        private string $invoiceNumber,
        private Money $money,
    ) {
        if (trim($invoiceNumber) === '') {
            throw new MetadataException('Invoice number must not be blank.');
        }

        if (mb_strlen($invoiceNumber) > 500) {
            throw new MetadataException('Invoice number exceeds Stripe metadata value limits.');
        }
    }

    /**
     * @brief Parse and validate the required version-1 fields from Stripe metadata.
     *
     * @param array<string, mixed> $metadata Stripe metadata map.
     *
     * @throws MetadataException When required fields are absent, malformed, or from an unknown contract version.
     */
    public static function fromStripeMetadata(array $metadata): self
    {
        $requiredFields = [
            'integration',
            'integration_version',
            'kimai_instance',
            'invoice_number',
            'invoice_amount',
            'invoice_currency',
        ];

        foreach ($requiredFields as $key) {
            if (!array_key_exists($key, $metadata) || !is_string($metadata[$key])) {
                throw new MetadataException(
                    sprintf('Missing or invalid required metadata field: %s.', $key)
                );
            }
        }

        if ($metadata['integration'] !== self::INTEGRATION) {
            throw new MetadataException('Stripe object is not managed by this connector.');
        }

        if ($metadata['integration_version'] !== self::VERSION) {
            throw new MetadataException('Unknown connector metadata version.');
        }

        if ($metadata['invoice_currency'] !== strtolower($metadata['invoice_currency'])) {
            throw new MetadataException('Invoice currency metadata must use the normalized lowercase code.');
        }

        if (preg_match('/^[1-9][0-9]*$/', $metadata['invoice_amount']) !== 1) {
            throw new MetadataException('Invoice amount metadata must be a positive integer minor-unit value.');
        }

        $phpIntMax = (string) PHP_INT_MAX;
        if (
            strlen($metadata['invoice_amount']) > strlen($phpIntMax)
            || (
                strlen($metadata['invoice_amount']) === strlen($phpIntMax)
                && strcmp($metadata['invoice_amount'], $phpIntMax) > 0
            )
        ) {
            throw new MetadataException('Invoice amount metadata exceeds the supported integer range.');
        }

        try {
            return new self(
                new KimaiInstanceId($metadata['kimai_instance']),
                $metadata['invoice_number'],
                Money::fromMinorUnits((int) $metadata['invoice_amount'], $metadata['invoice_currency']),
            );
        } catch (InvalidArgumentException $exception) {
            throw new MetadataException($exception->getMessage(), previous: $exception);
        } catch (ConfigurationException $exception) {
            throw new MetadataException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @brief Return exactly the connector-owned version-1 metadata fields for Stripe.
     *
     * @return array<string, string>
     */
    public function toStripeMetadata(): array
    {
        return [
            'integration' => self::INTEGRATION,
            'integration_version' => self::VERSION,
            'kimai_instance' => $this->instanceId->value(),
            'invoice_number' => $this->invoiceNumber,
            'invoice_amount' => (string) $this->money->minorUnits(),
            'invoice_currency' => $this->money->currency()->value(),
        ];
    }

    /**
     * @brief Return the stable Kimai instance identity.
     */
    public function instanceId(): KimaiInstanceId
    {
        return $this->instanceId;
    }

    /**
     * @brief Return the exact Kimai invoice number.
     */
    public function invoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    /**
     * @brief Return the exact invoice money represented by the metadata.
     */
    public function money(): Money
    {
        return $this->money;
    }
}
