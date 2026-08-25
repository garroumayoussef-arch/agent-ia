<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Télécharger le PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(fn (CreditNote $record): string => route('credit-notes.pdf', $record))
                ->openUrlInNewTab(),
        ];
    }
}
