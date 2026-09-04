<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCandidate;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkCreateRequest;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGateway;
use KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink\PaymentLinkGatewayException;

final class FakePaymentLinkGateway implements PaymentLinkGateway
{
    public int $listCalls = 0;
    public int $createCalls = 0;
    public ?PaymentLinkCreateRequest $lastCreateRequest = null;

    /**
     * @param list<list<PaymentLinkCandidate>|PaymentLinkGatewayException> $listResults
     */
    public function __construct(
        private array $listResults,
        private ?PaymentLinkCandidate $createCandidate = null,
        private ?PaymentLinkGatewayException $createException = null,
    ) {
    }

    /** @return list<PaymentLinkCandidate> */
    public function listAll(): array
    {
        $result = $this->listResults[$this->listCalls] ?? [];
        ++$this->listCalls;

        if ($result instanceof PaymentLinkGatewayException) {
            throw $result;
        }

        return $result;
    }

    public function create(PaymentLinkCreateRequest $request): PaymentLinkCandidate
    {
        ++$this->createCalls;
        $this->lastCreateRequest = $request;

        if ($this->createException !== null) {
            throw $this->createException;
        }

        if ($this->createCandidate === null) {
            throw new PaymentLinkGatewayException('Fake gateway has no create result configured.');
        }

        return $this->createCandidate;
    }
}
