<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ManagedPaymentLinkEvidence;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationGatewayException;
use KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation\ReconciliationStripeGateway;

final class StubReconciliationStripeGateway implements ReconciliationStripeGateway
{
    /** @var list<string> */
    public array $candidateRequests = [];

    /**
     * @param list<ManagedPaymentLinkEvidence> $links
     * @param array<string, list<ReconciliationCandidate>> $candidates
     * @param array<string, ReconciliationGatewayException> $candidateFailures
     * @param array<string, list<list<ReconciliationCandidate>>> $candidateSequences
     */
    public function __construct(
        private array $links,
        private array $candidates = [],
        private array $candidateFailures = [],
        private ?ReconciliationGatewayException $linkFailure = null,
        private array $candidateSequences = [],
    ) {
    }

    public function listManagedPaymentLinks(KimaiInstanceId $instanceId): array
    {
        if ($this->linkFailure !== null) {
            throw $this->linkFailure;
        }

        return $this->links;
    }

    public function listCandidates(ManagedPaymentLinkEvidence $paymentLink): array
    {
        $this->candidateRequests[] = $paymentLink->id();
        $id = $paymentLink->id();

        if (isset($this->candidateFailures[$id])) {
            throw $this->candidateFailures[$id];
        }

        if (isset($this->candidateSequences[$id]) && $this->candidateSequences[$id] !== []) {
            $sequence = $this->candidateSequences[$id];
            $next = array_shift($sequence);
            $this->candidateSequences[$id] = $sequence;

            if ($next !== null) {
                return $next;
            }
        }

        return $this->candidates[$id] ?? [new ReconciliationCandidate(
            $id,
            $paymentLink->metadata(),
        )];
    }
}
