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
            EditAction::make(),
        ];
    }
}
