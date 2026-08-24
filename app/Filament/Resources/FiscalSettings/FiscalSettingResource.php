<?php

namespace App\Filament\Resources\FiscalSettings;

use App\Filament\Resources\FiscalSettings\Pages\EditFiscalSetting;
use App\Filament\Resources\FiscalSettings\Pages\ListFiscalSettings;
use App\Filament\Resources\FiscalSettings\Schemas\FiscalSettingForm;
use App\Filament\Resources\FiscalSettings\Tables\FiscalSettingsTable;
use App\Models\FiscalSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class FiscalSettingResource extends Resource
{
    protected static ?string $model = FiscalSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'activity';

    protected static ?string $modelLabel = 'Régime fiscal';

    protected static ?string $pluralModelLabel = 'Régimes fiscaux';

    protected static ?string $navigationLabel = 'Régimes fiscaux';

    protected static string|UnitEnum|null $navigationGroup = 'Fiscalité';

    /*
     * =============================================================
     * AUTORISATION — RÉSERVÉE AUX ADMINS, Y COMPRIS EN LECTURE
     * =============================================================
     *
     * Même principe que UserResource (contrairement aux Resources
     * métier utilisant HasRoleBasedAuthorization) : le régime fiscal
     * réel de l'entreprise (franchise en base ou non) est une donnée
     * sensible, pas une simple donnée métier. Un manager/lecteur peut
     * toujours consulter le détail fiscal d'UNE course via
     * VtcRideResource (déjà ouvert à tous, cf. étape 5.3) — ce qui est
     * restreint ici, c'est uniquement le réglage global.
     *
     * Aucune page 'create'/'delete' n'est enregistrée (cf. getPages) :
     * canCreate()/canDelete() ne sont donc là que par cohérence avec
     * UserResource, pas parce qu'une de ces actions est accessible.
     */
    public static function canViewAny(): bool
    {
        return static::isAdmin();
    }

    public static function canView(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function canCreate(): bool
    {
        return static::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return static::isAdmin();
    }

    private static function isAdmin(): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return FiscalSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Ni 'create' ni 'delete' : les lignes fiscal_settings sont créées
     * par le système (cf. FiscalSettingSeeder), pas par un admin via ce
     * formulaire — leur `activity` est une clé fixe référencée en dur
     * ailleurs dans le code (VtcRide::resolveTaxRate()), une nouvelle
     * ligne créée ici ne serait rattachée à aucune logique.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFiscalSettings::route('/'),
            'edit' => EditFiscalSetting::route('/{record}/edit'),
        ];
    }
}
