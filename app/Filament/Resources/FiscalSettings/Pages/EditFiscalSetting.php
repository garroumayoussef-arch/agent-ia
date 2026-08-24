<?php

namespace App\Filament\Resources\FiscalSettings\Pages;

use App\Filament\Resources\FiscalSettings\FiscalSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditFiscalSetting extends EditRecord
{
    protected static string $resource = FiscalSettingResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de DeleteAction : la ligne fiscal_settings d'une activité
        // doit toujours exister (cf. FiscalSettingResource::getPages).
        return [];
    }
}
