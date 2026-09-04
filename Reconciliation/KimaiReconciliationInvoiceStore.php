<?php

/**
 * @file
 * @brief Adapts Kimai 2.65 invoice lookup and paid-state persistence to reconciliation.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use App\Entity\Invoice;
use App\Invoice\InvoiceService;
use App\Repository\InvoiceRepository;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata\InvoiceMetadata;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use Throwable;

/**
 * @brief Kimai-backed invoice store with a last-moment consistency check before mutation.
 *
 * @details
 * Kimai remains authoritative for invoice business state.  The adapter reads the
 * persisted invoice again immediately before a paid transition and compares its
 * exact identity, amount, and currency to the snapshot that was planned.  Any
 * drift fails closed before mutation.
 *
 * When a transition is safe, the payment date is derived from the proven Stripe
 * Charge creation timestamp, converted to the invoice creation timezone before
 * Kimai stores its date-only value.  The reconciliation execution time is not an
 * accounting event and is therefore not used as the payment date.
 */
final readonly class KimaiReconciliationInvoiceStore implements ReconciliationInvoiceStore
{
    public function __construct(
        private InvoiceRepository $repository,
        private InvoiceService $invoiceService,
    ) {
    }

    public function findByInvoiceNumber(
        KimaiInstanceId $instanceId,
        string $invoiceNumber,
    ): ?ReconciliationInvoice {
        try {
            $invoice = $this->findEntity($invoiceNumber);
            if ($invoice === null) {
                return null;
            }

            return $this->snapshot($invoice, $instanceId);
        } catch (ReconciliationPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ReconciliationPersistenceException(
                OperatorResult::RETRYABLE,
                'Kimai invoice lookup failed.',
                $exception,
            );
        }
    }

    public function markPaid(
        ReconciliationInvoice $invoice,
        ReconciliationCandidate $evidence,
    ): bool {
        $expected = $invoice->metadata();

        try {
            $currentEntity = $this->findEntity($expected->invoiceNumber());
            if ($currentEntity === null) {
                throw new ReconciliationPersistenceException(
                    OperatorResult::MANUAL_REVIEW_REQUIRED,
                    'Kimai invoice disappeared before the paid transition could be applied.',
                );
            }

            $current = $this->snapshot($currentEntity, $expected->instanceId());
            if (!$this->sameMetadata($expected, $current->metadata())) {
                throw new ReconciliationPersistenceException(
                    OperatorResult::CONFLICT,
                    'Kimai invoice identity, amount, or currency changed before paid-state persistence.',
                );
            }

            if (!$this->sameMetadata($expected, $evidence->paymentLinkMetadata())) {
                throw new ReconciliationPersistenceException(
                    OperatorResult::CONFLICT,
                    'Stripe evidence no longer matches the Kimai invoice selected for persistence.',
                );
            }

            if ($current->isPaid()) {
                return false;
            }

            $charge = $evidence->charge();
            $chargeCreatedAt = $charge?->createdAt();
            if ($chargeCreatedAt === null) {
                throw new ReconciliationPersistenceException(
                    OperatorResult::INTERNAL_ERROR,
                    'Proven Stripe Charge evidence is missing its payment timestamp.',
                );
            }

            $paymentDate = (new DateTimeImmutable('@' . $chargeCreatedAt))
                ->setTimezone(new DateTimeZone('UTC'));
            $invoiceCreatedAt = $currentEntity->getCreatedAt();
            if ($invoiceCreatedAt !== null) {
                $paymentDate = $paymentDate->setTimezone($invoiceCreatedAt->getTimezone());
            }

            $currentEntity->setPaymentDate(DateTime::createFromImmutable($paymentDate));
            $currentEntity->setIsPaid();
            $this->invoiceService->saveInvoice($currentEntity);

            return true;
        } catch (ReconciliationPersistenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ReconciliationPersistenceException(
                OperatorResult::RETRYABLE,
                'Kimai paid-state persistence failed.',
                $exception,
            );
        }
    }

    private function findEntity(string $invoiceNumber): ?Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = $this->repository->findOneBy(['invoiceNumber' => $invoiceNumber]);

        return $invoice;
    }

    /**
     * @throws ReconciliationPersistenceException When Kimai invoice data cannot satisfy the connector contract.
     */
    private function snapshot(Invoice $invoice, KimaiInstanceId $instanceId): ReconciliationInvoice
    {
        $invoiceNumber = $invoice->getInvoiceNumber();
        $currency = $invoice->getCurrency();
        if ($invoiceNumber === null || trim($invoiceNumber) === '' || $currency === null || trim($currency) === '') {
            throw new ReconciliationPersistenceException(
                OperatorResult::MANUAL_REVIEW_REQUIRED,
                'Kimai invoice identity or currency is incomplete.',
            );
        }

        try {
            $metadata = new InvoiceMetadata(
                $instanceId,
                $invoiceNumber,
                Money::fromFloat($invoice->getTotal(), $currency),
            );
        } catch (InvalidArgumentException $exception) {
            throw new ReconciliationPersistenceException(
                OperatorResult::MANUAL_REVIEW_REQUIRED,
                'Kimai invoice total or currency cannot be represented exactly by the connector policy.',
                $exception,
            );
        }

        return new ReconciliationInvoice($metadata, $invoice->isPaid());
    }

    private function sameMetadata(InvoiceMetadata $expected, InvoiceMetadata $actual): bool
    {
        return $expected->toStripeMetadata() === $actual->toStripeMetadata();
    }
}
