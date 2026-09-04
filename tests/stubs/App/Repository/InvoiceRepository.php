<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;

class InvoiceRepository
{
    /** @var array<string, Invoice> */
    private array $invoices = [];

    /** @var list<array<string, mixed>> */
    public array $findOneByCalls = [];

    /** @param array<string, Invoice> $invoices */
    public function __construct(array $invoices = [])
    {
        $this->invoices = $invoices;
    }

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria): ?Invoice
    {
        $this->findOneByCalls[] = $criteria;
        $invoiceNumber = $criteria['invoiceNumber'] ?? null;

        return is_string($invoiceNumber) ? ($this->invoices[$invoiceNumber] ?? null) : null;
    }

    public function put(string $invoiceNumber, Invoice $invoice): void
    {
        $this->invoices[$invoiceNumber] = $invoice;
    }

    public function remove(string $invoiceNumber): void
    {
        unset($this->invoices[$invoiceNumber]);
    }
}
