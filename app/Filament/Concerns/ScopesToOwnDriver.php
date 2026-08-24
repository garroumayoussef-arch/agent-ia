<?php

namespace App\Filament\Concerns;

use App\Models\Driver;
use Illuminate\Support\Facades\Auth;

/**
 * Scoping "chauffeur ne voit que ses propres données" — introduit à
 * l'étape 5.5 pour VtcRideResource, extrait ici pour être réutilisé à
 * l'identique par le dashboard/widget de l'étape 5.6, afin que la même
 * règle d'autorisation ne vive jamais en deux copies susceptibles de
 * diverger.
 *
 * - admin/manager : accès complet.
 * - un utilisateur lié à un Driver (Driver.user_id) : accès à SES
 *   propres données uniquement.
 * - ni l'un ni l'autre : aucun accès.
 */
trait ScopesToOwnDriver
{
    protected static function isAdminOrManager(): bool
    {
        return Auth::user()?->hasAnyRole(['admin', 'manager']) ?? false;
    }

    protected static function currentDriver(): ?Driver
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return Driver::where('user_id', $user->id)->first();
    }
}
