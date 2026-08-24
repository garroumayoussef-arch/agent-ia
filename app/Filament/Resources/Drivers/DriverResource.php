<?php

namespace App\Filament\Resources\Drivers;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Drivers\Pages\EditDriver;
use App\Filament\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Resources\Drivers\Schemas\DriverForm;
use App\Filament\Resources\Drivers\Tables\DriversTable;
use App\Models\Driver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DriverResource extends Resource
{
    use HasRoleBasedAuthorization;
    use ScopesToOwnDriver;

    protected static ?string $model = Driver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Chauffeur';

    protected static ?string $pluralModelLabel = 'Chauffeurs';

    protected static ?string $navigationLabel = 'Chauffeurs';

    /*
     * =============================================================
     * AUTORISATION — RÉSERVÉE À ADMIN/MANAGER, Y COMPRIS EN LECTURE
     * (étape 5.8)
     * =============================================================
     *
     * HasRoleBasedAuthorization ne restreint que les mutations : sans
     * ce chevauchement, la lecture resterait ouverte à tout utilisateur
     * authentifié — y compris un chauffeur (Driver.user_id, aucun rôle
     * Spatie), qui pourrait alors parcourir la fiche de TOUS les
     * chauffeurs (nom, permis, téléphone), pas seulement la sienne.
     * Aucun besoin métier de self-service ne justifie cet accès
     * aujourd'hui (contrairement à VtcRideResource, où un chauffeur DOIT
     * voir ses propres courses) : même principe que
     * UserResource/FiscalSettingResource (réservées, y compris en
     * lecture), pas de scoping "own" partiel ici.
     *
     * isAdminOrManager() vient de ScopesToOwnDriver (étape 5.6a),
     * réutilisé tel quel plutôt que dupliqué — cette Resource n'a pas
     * besoin de currentDriver() : un chauffeur n'a ici aucun accès du
     * tout, pas un accès à SES données.
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
        return DriverForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriversTable::configure($table);
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
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }
}
