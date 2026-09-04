<?php

declare(strict_types=1);

namespace App\Invoice;

interface CalculatorInterface
{
    public function getEntries(): array;

    public function setModel(InvoiceModel $model): void;

    public function getSubtotal(): float;

    public function getTax(): float;

    public function getTotal(): float;

    public function getVat(): float;

    public function getTaxRows(): array;

    public function getTimeWorked(): int;

    public function getId(): string;
}
