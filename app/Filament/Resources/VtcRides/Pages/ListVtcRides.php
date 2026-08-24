<?php

namespace App\Filament\Resources\VtcRides\Pages;

use App\Filament\Resources\VtcRides\VtcRideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVtcRides extends ListRecords
{
    protected static string $resource = VtcRideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
