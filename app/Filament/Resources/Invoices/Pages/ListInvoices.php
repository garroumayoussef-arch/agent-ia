<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Étape T23 — aucune action d'en-tête (pas de CreateAction) : une
 * facture ne se crée jamais depuis un formulaire, uniquement via
 * Invoice::generateFromSalesOrder() (action dédiée sur SalesOrder).
 */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
