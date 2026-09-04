<?php

declare(strict_types=1);

namespace App\Entity;

use DateTime;
use DateTimeInterface;

class Invoice
{
    public const STATUS_PAID = 'paid';
    public const STATUS_NEW = 'new';

    private ?string $invoiceNumber = null;
    private float $total = 0.0;
    private ?string $currency = null;
    private string $status = self::STATUS_NEW;
    private ?DateTime $paymentDate = null;
    private ?DateTime $createdAt = null;

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(string $invoiceNumber): void
    {
        $this->invoiceNumber = $invoiceNumber;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function setTotal(float $total): void
    {
        $this->total = $total;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function setIsPaid(): Invoice
    {
        $this->status = self::STATUS_PAID;

        return $this;
    }

    public function getPaymentDate(): ?DateTime
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(?DateTime $paymentDate): Invoice
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = DateTime::createFromInterface($createdAt);
    }
}
