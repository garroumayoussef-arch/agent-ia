<?php

namespace App\Filament\Resources\Vehicles;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Schemas\VehicleForm;
use App\Filament\Resources\Vehicles\Tables\VehiclesTable;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VehicleResource extends Resource
{
    use HasRoleBasedAuthorization;
    use ScopesToOwnDriver;

    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'plate_number';

    protected static ?string $modelLabel = 'Véhicule';

    protected static ?string $pluralModelLabel = 'Véhicules';

    protected static ?string $navigationLabel = 'Véhicules';

    /*
     * =============================================================
     * AUTORISATION — RÉSERVÉE À ADMIN/MANAGER, Y COMPRIS EN LECTURE
     * (étape 5.8)
     * =============================================================
     *
     * Même principe que DriverResource (cf. son commentaire détaillé) :
     * HasRoleBasedAuthorization ne restreint que les mutations, donc
     * sans ce chevauchement, un chauffeur pourrait parcourir TOUT le
     * parc de véhicules. Vehicle n'a de toute façon aucune notion de
     * "propriétaire" (pas de colonne driver_id : un véhicule est
     * référencé par n'importe quelle course), donc un scoping "own"
     * n'aurait ici aucun sens métier — c'est admin/manager ou rien.
     */
    public static function canViewAny(): bool
    {
        return static::isAdminOrManager();
    }

    public static function canView(Model $record): bool
    {
        return static::isAdminOrManager();
    }

    public static function form(Schema $schema): Schema
    {
        return VehicleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehiclesTable::configure($table);
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
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
