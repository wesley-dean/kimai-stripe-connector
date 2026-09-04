<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupService;
use KimaiPlugin\KimaiStripeConnectorBundle\Cleanup\CleanupServiceFactory;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use Throwable;

final class StubCleanupServiceFactory implements CleanupServiceFactory
{
    /** @var list<StripeCredential> */
    public array $credentials = [];

    public function __construct(
        private CleanupService $service,
        private ?Throwable $failure = null,
    ) {
    }

    public function create(StripeCredential $credential): CleanupService
    {
        $this->credentials[] = $credential;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->service;
    }
}
