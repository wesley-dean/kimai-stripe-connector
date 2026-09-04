<?php

/**
 * @file
 * @brief Exposes conservative orphan cleanup as a Kimai console command.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @brief Scheduler-safe cleanup command with stable ADR-013 result categories.
 *
 * @details
 * Cleanup is an operator-invoked maintenance action on the reconciliation side of
 * the plugin.  It accepts no Payment Link or invoice selector, so callers cannot
 * use it as a generic Stripe mutation endpoint.  Eligibility is derived entirely
 * from current connector configuration, Kimai absence, Stripe evidence, and the
 * configured grace period.
 */
#[AsCommand(
    name: 'kimai:stripe:cleanup',
    description: 'Deactivate only proven stale unpaid connector-managed Stripe Payment Link orphans.',
)]
final class CleanupCommand extends Command
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_RETRYABLE = 10;
    public const EXIT_CONFIGURATION_ERROR = 20;
    public const EXIT_CONFLICT = 30;
    public const EXIT_MANUAL_REVIEW_REQUIRED = 40;
    public const EXIT_INTERNAL_ERROR = 50;

    public function __construct(
        private readonly EnvironmentConfigurationLoader $configurationLoader,
        private readonly CleanupServiceFactory $serviceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Inspect cleanup eligibility without deactivating Stripe Payment Links.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $configuration = $this->configurationLoader->loadFromEnvironment();
        } catch (ConfigurationException $exception) {
            $this->writeResult($output, OperatorResult::CONFIGURATION_ERROR, $exception->getMessage());

            return self::EXIT_CONFIGURATION_ERROR;
        }

        if (!$configuration->enabled() || !$configuration->reconciliationEnabled()) {
            $this->writeResult(
                $output,
                OperatorResult::NO_CHANGE,
                'Cleanup is disabled because the reconciliation capability is disabled by configuration.',
            );

            return self::EXIT_SUCCESS;
        }

        $instanceId = $configuration->instanceId();
        $credential = $configuration->stripeCredential();
        if ($instanceId === null || $credential === null) {
            $this->writeResult(
                $output,
                OperatorResult::INTERNAL_ERROR,
                'Active cleanup configuration is missing validated runtime dependencies.',
            );

            return self::EXIT_INTERNAL_ERROR;
        }

        try {
            $service = $this->serviceFactory->create($credential);
            $outcomes = $service->cleanup(
                $instanceId,
                $configuration->cleanupGraceDays(),
                (bool) $input->getOption('dry-run'),
            );
        } catch (ReconciliationGatewayException $exception) {
            $this->writeResult($output, $exception->result(), $exception->getMessage());

            return self::exitCode($exception->result());
        } catch (CleanupGatewayException $exception) {
            $this->writeResult($output, $exception->result(), $exception->getMessage());

            return self::exitCode($exception->result());
        } catch (Throwable) {
            $this->writeResult(
                $output,
                OperatorResult::INTERNAL_ERROR,
                'Unexpected cleanup command failure.',
            );

            return self::EXIT_INTERNAL_ERROR;
        }

        if ($outcomes === []) {
            $this->writeResult(
                $output,
                OperatorResult::NO_CHANGE,
                'No connector-managed Stripe Payment Links were found for this Kimai instance.',
            );

            return self::EXIT_SUCCESS;
        }

        $exitCode = self::EXIT_SUCCESS;
        foreach ($outcomes as $outcome) {
            $output->writeln(sprintf(
                '[%s] invoice=%s payment_link=%s would_change=%s changed=%s %s',
                $outcome->result()->value,
                $outcome->invoiceNumber(),
                $outcome->paymentLinkId() ?? 'multiple',
                $outcome->wouldChange() ? 'yes' : 'no',
                $outcome->changed() ? 'yes' : 'no',
                $outcome->reason(),
            ));
            $exitCode = max($exitCode, self::exitCode($outcome->result()));
        }

        return $exitCode;
    }

    private function writeResult(OutputInterface $output, OperatorResult $result, string $message): void
    {
        $output->writeln(sprintf('[%s] %s', $result->value, $message));
    }

    private static function exitCode(OperatorResult $result): int
    {
        return match ($result) {
            OperatorResult::SUCCESS, OperatorResult::NO_CHANGE => self::EXIT_SUCCESS,
            OperatorResult::RETRYABLE => self::EXIT_RETRYABLE,
            OperatorResult::CONFIGURATION_ERROR => self::EXIT_CONFIGURATION_ERROR,
            OperatorResult::CONFLICT => self::EXIT_CONFLICT,
            OperatorResult::MANUAL_REVIEW_REQUIRED => self::EXIT_MANUAL_REVIEW_REQUIRED,
            OperatorResult::INTERNAL_ERROR => self::EXIT_INTERNAL_ERROR,
        };
    }
}
