<?php

namespace App\Models;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}

/**
 * L'accès au panel reste ouvert à tout utilisateur authentifié, y
 * compris sans aucun rôle assigné : un utilisateur "sans rôle" est
 * traité comme lecteur (accès en lecture seule) plutôt que bloqué à la
 * porte. C'est l'autorisation fine (créer/modifier/supprimer),
 * contrôlée par HasRoleBasedAuthorization sur chaque Resource, qui
 * distingue admin/manager/viewer — pas cette méthode.
 */
public function canAccessPanel(Panel $panel): bool
{
    return true;
}

/**
 * Étape T19 — entrepôts attribués à cet utilisateur (pivot
 * warehouse_user). N'a d'effet réel que pour un utilisateur ayant le
 * rôle manager (cf. ScopesToOwnWarehouses, D3) : un admin garde un
 * accès global quel que soit le contenu de cette relation, et elle
 * reste simplement inutilisée pour viewer/sans rôle.
 */
public function warehouses(): BelongsToMany
{
    // Nom de pivot explicite : la convention alphabétique par défaut
    // d'Eloquent donnerait "user_warehouse", alors que la table (D7)
    // s'appelle "warehouse_user".
    return $this->belongsToMany(Warehouse::class, 'warehouse_user');
}
}

