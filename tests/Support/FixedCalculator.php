<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Support;

use App\Invoice\CalculatorInterface;
use App\Invoice\InvoiceModel;

final class FixedCalculator implements CalculatorInterface
{
    public function __construct(private readonly float $total)
    {
    }

    public function getEntries(): array
    {
        return [];
    }

    public function setModel(InvoiceModel $model): void
    {
    }

    public function getSubtotal(): float
    {
        return $this->total;
    }

    public function getTax(): float
    {
        return 0.0;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getVat(): float
    {
        return 0.0;
    }

    public function getTaxRows(): array
    {
        return [];
    }

    public function getTimeWorked(): int
    {
        return 0;
    }

    public function getId(): string
    {
        return 'test-fixed';
    }
}
