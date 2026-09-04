<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Invoice;

class InvoiceService
{
    /** @var list<Invoice> */
    public array $savedInvoices = [];

    public function saveInvoice(Invoice $invoice): void
    {
        $this->savedInvoices[] = $invoice;
    }
}
