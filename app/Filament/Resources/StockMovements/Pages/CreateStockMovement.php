<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

/**
 * Étape T19 (D5) — barrière autoritaire pour le champ warehouse_id
 * ajouté à StockMovementForm : les options du Select ne sont qu'un
 * confort d'UI (StockMovementForm), jamais confiance dans une valeur
 * venue du navigateur. Rejoue ici exactement le même contrôle que
 * ValidatesOperationWarehouse/StockTransfer::execute() (D4/D5/D6),
 * mais au niveau de la page plutôt que du modèle StockMovement
 * lui-même : ce dernier reste composé par de nombreux appelants
 * internes déjà autorisés en amont (legs de transfert, réception,
 * expédition, seeders) qui ne doivent jamais être re-filtrés par le
 * périmètre entrepôt de l'utilisateur courant.
 */
class CreateStockMovement extends CreateRecord
{
    use ScopesToOwnWarehouses;

    protected static string $resource = StockMovementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            static::assertWarehouseIsInScope($data['warehouse_id'] ?? null);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Mouvement impossible')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt();
        }

        return $data;
    }
}
