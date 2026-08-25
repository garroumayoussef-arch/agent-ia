<?php

namespace App\Filament\Resources\WarehouseStocks\Pages;

use App\Filament\Resources\WarehouseStocks\WarehouseStockResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Étape T14 — aucune action d'en-tête (pas de CreateAction) :
 * warehouse_stocks est en lecture seule, cf. WarehouseStockResource.
 */
class ListWarehouseStocks extends ListRecords
{
    protected static string $resource = WarehouseStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
