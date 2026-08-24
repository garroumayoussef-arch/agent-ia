<?php

namespace App\Filament\Resources\VtcRides;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Resources\VtcRides\Pages\CreateVtcRide;
use App\Filament\Resources\VtcRides\Pages\EditVtcRide;
use App\Filament\Resources\VtcRides\Pages\ListVtcRides;
use App\Filament\Resources\VtcRides\Pages\ViewVtcRide;
use App\Filament\Resources\VtcRides\Schemas\VtcRideForm;
use App\Filament\Resources\VtcRides\Schemas\VtcRideInfolist;
use App\Filament\Resources\VtcRides\Tables\VtcRidesTable;
use App\Models\VtcRide;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VtcRideResource extends Resource
{
    use ScopesToOwnDriver;

    protected static ?string $model = VtcRide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Course VTC';

    protected static ?string $pluralModelLabel = 'Courses VTC';

    protected static ?string $navigationLabel = 'Courses VTC';

    /*
     * =============================================================
     * AUTORISATION — PAR RÔLE *ET* PAR PROPRIÉTAIRE (étape 5.5)
     * =============================================================
     *
     * N'utilise volontairement PAS HasRoleBasedAuthorization : cette
     * Resource restreint l'accès par ENREGISTREMENT (le chauffeur
     * propriétaire de la course), pas seulement par rôle.
     * isAdminOrManager()/currentDriver() viennent de ScopesToOwnDriver
     * (étape 5.6), extrait d'ici pour être réutilisé à l'identique par
     * le dashboard/widget VTC — la même règle ne doit jamais vivre en
     * deux copies susceptibles de diverger.
     *
     * - admin/manager : accès complet, inchangé (comme avant l'étape 5.5).
     * - un utilisateur lié à un Driver (Driver.user_id) : accès à SES
     *   propres courses uniquement (cf. getEloquentQuery), jamais à
     *   celles d'un autre chauffeur, y compris par URL directe (canView/
     *   canEdit revérifient explicitement la propriété, indépendamment
     *   du filtrage de la liste).
     * - un utilisateur sans Driver associé (et sans rôle admin/manager) :
     *   aucun accès, y compris à la liste elle-même (canViewAny).
     *
     * canCreate()/canDelete()/canDeleteAny() restent réservés à
     * admin/manager : un chauffeur ne crée ni ne supprime de course
     * (seule la MODIFICATION de ses propres courses en brouillon lui
     * est ouverte, cf. canEdit) — non explicitement demandé mais évite
     * qu'un chauffeur puisse créer une course et l'assigner à un autre
     * chauffeur via le champ driver_id du formulaire, qui reste ouvert
     * à tous les chauffeurs existants.
     */
    public static function canViewAny(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() !== null;
    }

    public static function canView(Model $record): bool
    {
        if (static::isAdminOrManager()) {
            return true;
        }

        $driver = static::currentDriver();

        return $driver !== null && (int) $record->driver_id === $driver->id;
    }

    public static function canCreate(): bool
    {
        return static::isAdminOrManager();
    }

    public static function canEdit(Model $record): bool
    {
        if (static::isAdminOrManager()) {
            return true;
        }

        $driver = static::currentDriver();

        // Un chauffeur ne modifie que SES courses, et seulement tant
        // qu'elles sont en brouillon — une fois confirmée, la course
        // (montants, taux, historique fiscal) est intégralement figée
        // par VtcRide::updating() de toute façon, mais on lui retire ici
        // même l'accès au formulaire d'édition plutôt que de le laisser
        // ouvrir un formulaire dont plus aucun champ financier ne
        // pourrait être modifié.
        return $driver !== null
            && (int) $record->driver_id === $driver->id
            && $record->status === VtcRide::STATUS_DRAFT;
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdminOrManager();
    }

    public static function canDeleteAny(): bool
    {
        return static::isAdminOrManager();
    }

    /**
     * Filtre la liste (et toute requête sur la Resource) aux seules
     * courses du chauffeur connecté, sauf pour admin/manager. C'est ce
     * qui garantit qu'un chauffeur ne voit jamais apparaître la course
     * d'un autre chauffeur, y compris indirectement (recherche,
     * export...), pas seulement dans le rendu de la table.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (static::isAdminOrManager()) {
            return $query;
        }

        $driver = static::currentDriver();

        if ($driver === null) {
            // Défensif : canViewAny() bloque déjà ce cas en amont, mais
            // on ne laisse jamais la requête non filtrée s'exécuter.
            return $query->whereRaw('1 = 0');
        }

        return $query->where('driver_id', $driver->id);
    }

    public static function form(Schema $schema): Schema
    {
        return VtcRideForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VtcRideInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VtcRidesTable::configure($table);
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
            'index' => ListVtcRides::route('/'),
            'create' => CreateVtcRide::route('/create'),
            'view' => ViewVtcRide::route('/{record}'),
            'edit' => EditVtcRide::route('/{record}/edit'),
        ];
    }
}
