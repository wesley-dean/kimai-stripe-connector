<?php

declare(strict_types=1);

namespace App\Invoice;

interface InvoiceModelHydrator
{
    public function hydrate(InvoiceModel $model): array;
}
