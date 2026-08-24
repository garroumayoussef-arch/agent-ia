<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Chantier transversal (T3, séparé du module VTC) : bloque la lecture
 * d'une Resource pour un compte CHAUFFEUR — défini exclusivement comme
 * un utilisateur authentifié lié à un Driver (Driver.user_id), jamais
 * déduit de l'absence de rôle (décision explicite T2). N'importe quel
 * autre profil (y compris un utilisateur sans rôle et sans Driver
 * associé) garde exactement le comportement déjà en place via
 * HasRoleBasedAuthorization — ce trait ne le modifie pas, il ajoute
 * une exclusion supplémentaire, ciblée uniquement sur le chauffeur.
 *
 * Réutilise isAdminOrManager()/currentDriver() de ScopesToOwnDriver
 * (étape 5.6a) par composition, plutôt que de les redéfinir — mais la
 * combinaison ("tout le monde SAUF un chauffeur") est différente de
 * VtcRideResource/DriverResource/VehicleResource (qui appliquent
 * "admin/manager OU propriétaire" ou "admin/manager seulement") : elle
 * n'existait pas encore dans le projet, d'où ce nouveau trait plutôt
 * qu'une réutilisation directe de l'un des mécanismes existants.
 *
 * Destiné à être composé À CÔTÉ de HasRoleBasedAuthorization (qui ne
 * couvre que l'écriture) : aucune méthode en commun entre les deux,
 * aucun conflit de composition.
 */
trait BlocksChauffeurReadAccess
{
    use ScopesToOwnDriver;

    public static function canViewAny(): bool
    {
        return ! static::isChauffeurAccount();
    }

    public static function canView(Model $record): bool
    {
        return ! static::isChauffeurAccount();
    }

    private static function isChauffeurAccount(): bool
    {
        return ! static::isAdminOrManager() && static::currentDriver() !== null;
    }
}
