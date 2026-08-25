<?php

namespace App\Filament\Resources\WarehouseStocks;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Resources\WarehouseStocks\Tables\WarehouseStocksTable;
use App\Models\WarehouseStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Étape T14 — écran de consultation de la décomposition du stock par
 * entrepôt (warehouse_stocks), manquant depuis T11a.
 *
 * Strictement en LECTURE SEULE : warehouse_stocks est une donnée
 * entièrement DÉRIVÉE de l'historique stock_movements (T11b/T12) — son
 * `stock` n'a de sens que comme le résultat cumulé des mouvements.
 * Une édition manuelle romprait silencieusement cette cohérence (le
 * compteur de l'entrepôt divergerait de l'historique ET du stock
 * global Product/ProductVariant, qui ne serait jamais mis à jour par
 * une telle édition directe). Aucune page create/edit n'existe donc
 * ici, volontairement — voir getPages() : seule 'index' est déclarée.
 *
 * HasRoleBasedAuthorization composé par cohérence avec les 12 autres
 * Resources, même si ses méthodes de mutation (canCreate/canEdit/
 * canDelete) restent inertes en pratique : aucune page/action de
 * mutation n'existe pour les invoquer.
 */
class WarehouseStockResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;

    protected static ?string $model = WarehouseStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $modelLabel = 'Stock par entrepôt';

    protected static ?string $pluralModelLabel = 'Stocks par entrepôt';

    protected static ?string $navigationLabel = 'Stocks par entrepôt';

    public static function table(Table $table): Table
    {
        return WarehouseStocksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouseStocks::route('/'),
        ];
    }
}
