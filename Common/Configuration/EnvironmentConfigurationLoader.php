<?php

/**
 * @file
 * @brief Parses connector-owned environment variables into validated configuration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration;

use Closure;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;

/**
 * @brief Loads the first implementation's environment-based configuration contract.
 */
final class EnvironmentConfigurationLoader
{
    public const PREFIX = 'KIMAI_STRIPE_CONNECTOR_';
    public const DEFAULT_CLEANUP_GRACE_DAYS = 7;
    public const MIN_CLEANUP_GRACE_DAYS = 1;
    public const MAX_CLEANUP_GRACE_DAYS = 3650;

    /** @var Closure(string): string */
    private Closure $readSecretFile;

    /**
     * @brief Construct a loader with an optional testable secret-file boundary.
     *
     * @param callable(string): string|null $readSecretFile Optional reader used for mounted secrets.
     */
    public function __construct(?callable $readSecretFile = null)
    {
        $this->readSecretFile = $readSecretFile === null
            ? static function (string $path): string {
                if (!is_file($path)) {
                    throw new ConfigurationException('Configured Stripe secret file does not exist.');
                }

                if (!is_readable($path)) {
                    throw new ConfigurationException('Configured Stripe secret file is not readable.');
                }

                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new ConfigurationException('Configured Stripe secret file could not be read.');
                }

                return $contents;
            }
            : Closure::fromCallable($readSecretFile);
    }

    /**
     * @brief Load connector configuration from the current process environment.
     *
     * @throws ConfigurationException When any connector-owned value is invalid or contradictory.
     */
    public function loadFromEnvironment(): ConnectorConfiguration
    {
        $environment = [];

        foreach ($this->ownedVariableNames() as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        return $this->load($environment);
    }

    /**
     * @brief Load connector configuration from an explicit environment-style map.
     *
     * @param array<string, mixed> $environment Environment variable values.
     *
     * @throws ConfigurationException When any connector-owned value is invalid or contradictory.
     */
    public function load(array $environment): ConnectorConfiguration
    {
        $enabled = $this->boolean($environment, self::PREFIX . 'ENABLED', false);
        $paymentLinksEnabled = $this->boolean($environment, self::PREFIX . 'PAYMENT_LINKS_ENABLED', false);
        $reconciliationEnabled = $this->boolean(
            $environment,
            self::PREFIX . 'RECONCILIATION_ENABLED',
            false,
        );
        $cleanupGraceDays = $this->positiveInteger(
            $environment,
            self::PREFIX . 'CLEANUP_GRACE_DAYS',
            self::DEFAULT_CLEANUP_GRACE_DAYS,
            self::MIN_CLEANUP_GRACE_DAYS,
            self::MAX_CLEANUP_GRACE_DAYS,
        );

        $instanceIdValue = $this->optionalString($environment, self::PREFIX . 'INSTANCE_ID');
        $instanceId = $instanceIdValue === null ? null : new KimaiInstanceId($instanceIdValue);
        $stripeCredential = $this->stripeCredential($environment);
        $capabilityActive = $enabled && ($paymentLinksEnabled || $reconciliationEnabled);

        if ($capabilityActive && $instanceId === null) {
            throw new ConfigurationException('An instance UUID is required when a Stripe capability is active.');
        }

        if ($capabilityActive && $stripeCredential === null) {
            throw new ConfigurationException('A Stripe restricted API credential is required when a Stripe capability is active.');
        }

        return new ConnectorConfiguration(
            $enabled,
            $paymentLinksEnabled,
            $reconciliationEnabled,
            $cleanupGraceDays,
            $instanceId,
            $stripeCredential,
        );
    }

    /**
     * @brief Return all environment variables owned by the connector configuration contract.
     *
     * @return list<string>
     */
    private function ownedVariableNames(): array
    {
        return [
            self::PREFIX . 'ENABLED',
            self::PREFIX . 'INSTANCE_ID',
            self::PREFIX . 'PAYMENT_LINKS_ENABLED',
            self::PREFIX . 'RECONCILIATION_ENABLED',
            self::PREFIX . 'CLEANUP_GRACE_DAYS',
            self::PREFIX . 'STRIPE_API_KEY',
            self::PREFIX . 'STRIPE_API_KEY_FILE',
        ];
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function stripeCredential(array $environment): ?StripeCredential
    {
        $direct = $this->optionalString($environment, self::PREFIX . 'STRIPE_API_KEY');
        $file = $this->optionalString($environment, self::PREFIX . 'STRIPE_API_KEY_FILE');

        if ($direct !== null && $file !== null) {
            throw new ConfigurationException('Direct and file-backed Stripe credential forms cannot both be configured.');
        }

        if ($direct !== null) {
            return new StripeCredential($direct, CredentialSource::DIRECT);
        }

        if ($file === null) {
            return null;
        }

        if ($file === '') {
            throw new ConfigurationException('Configured Stripe secret file path must not be blank.');
        }

        $secret = trim(($this->readSecretFile)($file));
        if ($secret === '') {
            throw new ConfigurationException('Configured Stripe secret file is blank after trimming.');
        }

        return new StripeCredential($secret, CredentialSource::FILE);
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function boolean(array $environment, string $name, bool $default): bool
    {
        $value = $this->optionalString($environment, $name);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new ConfigurationException(sprintf('%s must be true, false, 1, or 0.', $name)),
        };
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function positiveInteger(
        array $environment,
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $this->optionalString($environment, $name);
        if ($value === null) {
            return $default;
        }

        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ConfigurationException(sprintf('%s must be a positive integer.', $name));
        }

        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new ConfigurationException(
                sprintf('%s must be between %d and %d.', $name, $minimum, $maximum)
            );
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function optionalString(array $environment, string $name): ?string
    {
        if (!array_key_exists($name, $environment)) {
            return null;
        }

        if (!is_string($environment[$name])) {
            throw new ConfigurationException(sprintf('%s must be supplied as a string value.', $name));
        }

        return $environment[$name];
    }
}
