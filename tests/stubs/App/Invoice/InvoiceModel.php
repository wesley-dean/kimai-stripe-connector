<?php

declare(strict_types=1);

namespace App\Invoice;

final class InvoiceModel
{
    /** @var array<string, string|array<string|int, mixed>|null|bool|int|float> */
    private array $options = [];

    /** @var list<InvoiceModelHydrator> */
    private array $hydrators = [];

    public function __construct(
        private bool $preview,
        private ?CalculatorInterface $calculator,
        private string $invoiceNumber,
        private string $currency,
    ) {
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function getCalculator(): ?CalculatorInterface
    {
        return $this->calculator;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @param string|array<string|int, mixed>|null|bool|int|float $value
     */
    public function setOption(string $key, string|array|null|bool|int|float $value): void
    {
        $this->options[$key] = $value;
    }

    /** @return array<string, string|array<string|int, mixed>|null|bool|int|float> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function addModelHydrator(InvoiceModelHydrator $hydrator): InvoiceModel
    {
        $this->hydrators[] = $hydrator;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = [];

        foreach ($this->hydrators as $hydrator) {
            $values = array_merge($values, $hydrator->hydrate($this));
        }

        return $values;
    }
}
