<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Filament\Resources\SupplierInvoices\Pages\Concerns\HasSupplierInvoicePaymentAction;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierInvoice extends ViewRecord
{
    use HasSupplierInvoicePaymentAction;

    protected static string $resource = SupplierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->recordPaymentAction(),
        ];
    }
}
