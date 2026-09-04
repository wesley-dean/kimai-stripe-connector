<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationService;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationServiceFactory;

final class StubReconciliationServiceFactory implements ReconciliationServiceFactory
{
    /** @var list<StripeCredential> */
    public array $credentials = [];

    public function __construct(private ReconciliationService $service)
    {
    }

    public function create(StripeCredential $credential): ReconciliationService
    {
        $this->credentials[] = $credential;

        return $this->service;
    }
}
