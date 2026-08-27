<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Pages\Concerns\HasCreditNoteReturnAction;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditNote extends ViewRecord
{
    use HasCreditNoteReturnAction;

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

            // Chantier "retour physique" (Option 3b) — action séparée,
            // postérieure dans le temps à l'émission de l'avoir.
            $this->recordReturnAction(),
        ];
    }
}
