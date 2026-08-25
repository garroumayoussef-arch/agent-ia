<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\Resources\StockMovements\Pages\EditStockMovement;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Resources\StockMovements\Schemas\StockMovementInfolist;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Étape T20 (manager) / T21 (viewer) — scoping en LECTURE par
 * entrepôt, via getEloquentQuery() (cf. getEloquentQuery() ci-dessous).
 * Ce point unique protège à la fois la liste ET les pages "view"/"edit" :
 * Filament résout l'enregistrement de ces pages via cette même
 * requête, un accès direct par URL à une fiche hors périmètre devient
 * donc un 404 natif, sans logique supplémentaire à écrire (D3 : chaque
 * StockMovement porte son propre warehouse_id — la jambe transfer_out
 * et la jambe transfer_in d'un même transfert sont donc déjà scopées
 * indépendamment l'une de l'autre, sans code spécifique aux
 * transferts).
 */
class StockMovementResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;
    use ScopesToOwnWarehouses;

    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockMovementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    /**
     * Étape T20 (manager) / T21 (viewer, D3) — barrière autoritaire côté
     * serveur (jamais seulement l'UI, cf. le filtre déjà restreint dans
     * StockMovementsTable) : un manager ou un viewer sans entrepôt
     * attribué obtient une liste vide (aucune exception) et un 404 sur
     * toute fiche hors périmètre ; admin/sans rôle : inchangé.
     * currentUserReadWarehouseIds() (T21, D5) — jamais
     * currentUserWarehouseIds() (T19, écriture) — pour ne jamais
     * affecter les permissions admin/manager déjà validées.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $allowedWarehouseIds = static::currentUserReadWarehouseIds();

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
            'index' => ListStockMovements::route('/'),
            'create' => CreateStockMovement::route('/create'),
            'view' => ViewStockMovement::route('/{record}'),
            'edit' => EditStockMovement::route('/{record}/edit'),
        ];
    }
}
