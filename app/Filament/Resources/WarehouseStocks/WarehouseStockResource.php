<?php

namespace App\Filament\Resources\WarehouseStocks;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Resources\WarehouseStocks\Tables\WarehouseStocksTable;
use App\Models\WarehouseStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
 *
 * Étape T20 — scoping en LECTURE par entrepôt (managers uniquement,
 * D1) : getEloquentQuery() est le point d'extension officiel de
 * Filament pour ça (cf. son commentaire natif "Security: Override this
 * method to scope queries..."). Comme cette Resource n'a qu'une page
 * "index" (aucune page "view"/"edit", cf. getPages() ci-dessous), seule
 * la liste est concernée — pas de fiche accessible par URL directe à
 * protéger séparément ici.
 */
class WarehouseStockResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;
    use ScopesToOwnWarehouses;

    protected static ?string $model = WarehouseStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $modelLabel = 'Stock par entrepôt';

    protected static ?string $pluralModelLabel = 'Stocks par entrepôt';

    protected static ?string $navigationLabel = 'Stocks par entrepôt';

    public static function table(Table $table): Table
    {
        return WarehouseStocksTable::configure($table);
    }

    /**
     * Étape T20 — barrière autoritaire côté serveur (jamais seulement
     * l'UI, cf. le filtre déjà restreint dans WarehouseStocksTable) :
     * un manager sans entrepôt attribué obtient une liste vide (aucune
     * exception, une requête filtrée sur un ensemble vide ne retourne
     * simplement aucune ligne) ; admin/viewer/sans rôle : inchangé.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $allowedWarehouseIds = static::currentUserWarehouseIds();

        if ($allowedWarehouseIds !== null) {
            $query->whereIn('warehouse_id', $allowedWarehouseIds);
        }

        return $query;
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
