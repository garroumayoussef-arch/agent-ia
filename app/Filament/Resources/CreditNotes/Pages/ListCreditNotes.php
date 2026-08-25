<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Étape T24 — aucune action d'en-tête (pas de CreateAction) : un avoir
 * ne se crée jamais depuis un formulaire libre, uniquement via
 * CreditNote::generateFromInvoice() (actions dédiées sur ViewInvoice).
 */
class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
