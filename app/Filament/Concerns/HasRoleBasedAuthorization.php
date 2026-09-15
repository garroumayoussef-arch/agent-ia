<?php

namespace App\Filament\Concerns;

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
 *
 * La règle elle-même (currentUserCanMutate()) vit dans
 * DeterminesMutationAccessByRole (étape D2.4.4), extraite pour être
 * réutilisable par des classes dont la forme des can*() diffère de
 * celle d'une Resource (ex. RelationManager) — comportement de ce
 * trait strictement inchangé par cette extraction.
 */
trait HasRoleBasedAuthorization
{
    use DeterminesMutationAccessByRole;

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
}
