<?php

namespace App\Filament\Resources\FiscalSettings\Pages;

use App\Filament\Resources\FiscalSettings\FiscalSettingResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalSettings extends ListRecords
{
    protected static string $resource = FiscalSettingResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de CreateAction : cf. FiscalSettingResource::getPages().
        return [];
    }
}
