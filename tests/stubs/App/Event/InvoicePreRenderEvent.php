<?php

declare(strict_types=1);

namespace App\Event;

use App\Invoice\InvoiceModel;

final class InvoicePreRenderEvent
{
    public function __construct(private readonly InvoiceModel $model)
    {
    }

    public function getModel(): InvoiceModel
    {
        return $this->model;
    }
}
