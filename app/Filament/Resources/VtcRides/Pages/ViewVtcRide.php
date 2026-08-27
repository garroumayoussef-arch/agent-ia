<?php

namespace App\Filament\Resources\VtcRides\Pages;

use App\Filament\Resources\VtcRides\Pages\Concerns\HasVtcRideWorkflowActions;
use App\Filament\Resources\VtcRides\VtcRideResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVtcRide extends ViewRecord
{
    use HasVtcRideWorkflowActions;

    protected static string $resource = VtcRideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmRideAction(),
            $this->cancelRideAction(),
            $this->receiptAction(),
            // Chantier "facturation légale VTC" (D5/D8, validés).
            $this->generateInvoiceAction(),
            $this->downloadInvoiceAction(),
            EditAction::make(),
        ];
    }
}
