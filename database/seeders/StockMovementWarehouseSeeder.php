<?php

namespace Database\Seeders;

use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Étape T11b — seeder de RÉCONCILIATION (même famille que
 * WarehouseSeeder/WarehouseStockSeeder), idempotent, à exécuter en
 * production. Rattache les stock_movements HISTORIQUES (warehouse_id
 * encore NULL, colonne ajoutée par une migration de schéma pure, sans
 * DML) à l'unique entrepôt marqué par défaut.
 *
 * Ne touche JAMAIS une ligne dont warehouse_id est déjà renseigné
 * (mise à jour ciblée sur whereNull uniquement) : un mouvement déjà
 * attribué à un entrepôt précis n'est jamais écrasé.
 *
 * Mise à jour de masse via le query builder (pas de ->save() par
 * instance) : ne déclenche donc pas StockMovement::updating(), qui
 * bloquerait toute modification de warehouse_id après création.
 */
class StockMovementWarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $defaultWarehouse = Warehouse::where('is_default', true)->first();

        if (!$defaultWarehouse) {
            // Rien à faire sans entrepôt par défaut : cohérent avec
            // StockMovement::creating(), qui refuse désormais tout
            // nouveau mouvement sans entrepôt déterminable.
            return;
        }

        StockMovement::whereNull('warehouse_id')
            ->update(['warehouse_id' => $defaultWarehouse->id]);
    }
}
