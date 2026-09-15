<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Regle d'autorisation de mutation basee sur les roles (admin/manager),
 * extraite de HasRoleBasedAuthorization (etape D2.4.4) pour etre
 * reutilisable par des classes dont la forme des methodes can*() ne
 * correspond pas a celle des Resources Filament : un RelationManager
 * expose canCreate()/canEdit()/canDelete()/canDeleteAny() comme des
 * methodes d'instance protected (jamais statiques/publiques comme sur
 * une Resource), donc incompatibles avec HasRoleBasedAuthorization tel
 * quel.
 */
trait DeterminesMutationAccessByRole
{
    protected static function currentUserCanMutate(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'manager']);
    }
}
