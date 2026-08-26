<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\Concerns\HasInvoiceWorkflowActions;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use HasInvoiceWorkflowActions;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Télécharger le PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(fn (Invoice $record): string => route('invoices.pdf', $record))
                ->openUrlInNewTab(),
            $this->generateTotalCreditNoteAction(),
            $this->generatePartialCreditNoteAction(),
            $this->recordPaymentAction(),
        ];
    }
}
