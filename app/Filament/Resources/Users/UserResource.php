<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Utilisateur';

    protected static ?string $pluralModelLabel = 'Utilisateurs';

    /*
     * =============================================================
     * AUTORISATION — RÉSERVÉE AUX ADMINS, Y COMPRIS EN LECTURE
     * =============================================================
     *
     * Contrairement aux Resources métier (HasRoleBasedAuthorization),
     * la gestion des utilisateurs et de leurs rôles est réservée aux
     * admins même pour simplement CONSULTER la liste : un manager ou un
     * lecteur ne doit pas voir qui a quel rôle. shouldRegisterNavigation
     * suit canViewAny() par défaut (cf. Resource::canAccess()), donc
     * l'entrée de menu "Utilisateurs" disparaît aussi pour eux.
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
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
