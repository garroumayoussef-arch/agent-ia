<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Autorisation de mutation basée sur les rôles (admin/manager/viewer),
 * appliquée à toutes les Resources "métier" du panel (catalogue, stock,
 * achats, ventes...).
 *
 * Volontairement PAS appliqué à canViewAny()/canView() : la lecture
 * reste ouverte à tout utilisateur authentifié (y compris sans rôle,
 * traité comme lecteur) — cf. User::canAccessPanel(). Seules les
 * actions qui modifient des données sont restreintes à admin/manager.
 *
 * UserResource n'utilise PAS ce trait : la gestion des utilisateurs et
 * de leurs rôles est réservée aux admins, y compris en lecture (voir
 * ses propres méthodes can*()).
 */
trait HasRoleBasedAuthorization
{
    public static function canCreate(): bool
    {
        return static::currentUserCanMutate();
    }

    public static function canEdit($record): bool
    {
        return static::currentUserCanMutate();
    }

    public static function canDelete($record): bool
    {
        return static::currentUserCanMutate();
    }

    public static function canDeleteAny(): bool
    {
        return static::currentUserCanMutate();
    }

    private static function currentUserCanMutate(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'manager']);
    }
}
