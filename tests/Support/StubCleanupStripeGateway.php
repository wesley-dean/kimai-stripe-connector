<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupStripeGateway;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;

final class StubCleanupStripeGateway implements CleanupStripeGateway
{
    /** @var list<string> */
    public array $deactivateCalls = [];

    public function __construct(
        private bool $changed = true,
        private ?CleanupGatewayException $failure = null,
    ) {
    }

    public function deactivatePaymentLink(ManagedPaymentLinkEvidence $expected): bool
    {
        $this->deactivateCalls[] = $expected->id();

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->changed;
    }
}
