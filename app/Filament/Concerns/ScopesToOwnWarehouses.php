<?php

namespace App\Filament\Concerns;

use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Étape T19 — permissions par entrepôt (décisions D1-D7).
 *
 * Portée strictement limitée à l'ÉCRITURE (D1) : jamais composé sur
 * canViewAny()/canView() d'une Resource, la lecture reste inchangée
 * pour tout le monde, y compris viewer.
 *
 * Règle de résolution du périmètre courant :
 * - aucun utilisateur authentifié (contexte système : CLI, seeder, job,
 *   test sans actingAs()) : AUCUNE restriction — comportement T10-T18
 *   préservé à l'identique, ce trait n'existait pas avant T19.
 * - admin : AUCUNE restriction, accès global (cohérent avec le
 *   traitement déjà universel de l'admin partout ailleurs).
 * - manager : restreint à `$user->warehouses` — vide si aucun entrepôt
 *   ne lui est attribué (D2, fail-closed : aucun accès aux opérations
 *   d'écriture nécessitant un entrepôt, jamais un accès total par
 *   défaut).
 * - tout le reste (viewer, sans rôle, chauffeur...) : AUCUNE
 *   restriction ajoutée par ce trait (D3 — le rattachement warehouse_user
 *   ne concerne que les managers en T19, le comportement de viewer
 *   reste inchangé). En pratique, HasRoleBasedAuthorization bloque déjà
 *   ces profils AVANT qu'ils n'atteignent un point composant ce trait.
 */
trait ScopesToOwnWarehouses
{
    /**
     * @return Collection<int, int>|null null = aucune restriction (voir
     *                                    la règle de résolution ci-dessus).
     */
    protected static function currentUserWarehouseIds(): ?Collection
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if ($user->hasRole('admin')) {
            return null;
        }

        if (! $user->hasRole('manager')) {
            return null;
        }

        return $user->warehouses()->pluck('warehouses.id');
    }

    /**
     * Barrière autoritaire (jamais confiance dans une valeur venue du
     * navigateur, même seule protection de dernier recours si une
     * option d'UI restreinte a été contournée) : lève une exception
     * business si l'entrepôt donné n'appartient pas au périmètre de
     * l'utilisateur courant. No-op si aucune restriction ne s'applique.
     */
    protected static function assertWarehouseIsInScope(?int $warehouseId): void
    {
        $allowedWarehouseIds = static::currentUserWarehouseIds();

        if ($allowedWarehouseIds === null) {
            return;
        }

        if ($warehouseId === null || ! $allowedWarehouseIds->contains($warehouseId)) {
            throw new \Exception(
                "Vous n'êtes pas autorisé à opérer sur cet entrepôt : aucun entrepôt actif ne vous est attribué, ou l'entrepôt concerné n'en fait pas partie."
            );
        }
    }

    /**
     * Options d'entrepôts actifs pour un Select, déjà restreintes au
     * périmètre de l'utilisateur courant — simple confort d'UI/première
     * barrière, jamais la seule protection (assertWarehouseIsInScope()
     * reste la barrière autoritaire côté modèle/page).
     *
     * @return array<int, string>
     */
    protected static function activeWarehousesOptionsForCurrentUser(): array
    {
        $allowedWarehouseIds = static::currentUserWarehouseIds();

        return Warehouse::query()
            ->where('is_active', true)
            ->when(
                $allowedWarehouseIds !== null,
                fn ($query) => $query->whereIn('id', $allowedWarehouseIds),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
