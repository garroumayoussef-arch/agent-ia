<?php

namespace App\Filament\Concerns;

use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Étape T19 — permissions par entrepôt (décisions D1-D7). Étendu en
 * T21 (décisions D1-D5) pour couvrir aussi la lecture du rôle viewer.
 *
 * Deux méthodes de résolution du périmètre, JAMAIS interchangeables :
 * - currentUserWarehouseIds() — ÉCRITURE (T19), manager uniquement.
 *   Comportement inchangé depuis T19 (D5 T21 : ne jamais toucher cette
 *   méthode ni son résultat pour admin/manager). Composée par
 *   ValidatesOperationWarehouse, StockTransfer, CreateStockMovement,
 *   les Select d'entrepôt en écriture — jamais par un point de LECTURE.
 * - currentUserReadWarehouseIds() — LECTURE (T21), manager + viewer.
 *   Composée uniquement par WarehouseStockResource/StockMovementResource
 *   ::getEloquentQuery(), leurs filtres, et les widgets T15/T18. Jamais
 *   par un point d'ÉCRITURE : un viewer n'a et n'aura toujours aucun
 *   accès en écriture (HasRoleBasedAuthorization, inchangé).
 *
 * Règle de résolution commune (partagée via resolveScopedWarehouseIds,
 * seul l'ensemble de rôles concernés diffère entre les deux méthodes) :
 * - aucun utilisateur authentifié (contexte système : CLI, seeder, job,
 *   test sans actingAs()) : AUCUNE restriction — comportement T10-T18
 *   préservé à l'identique, ce trait n'existait pas avant T19.
 * - admin : AUCUNE restriction, accès global (cohérent avec le
 *   traitement déjà universel de l'admin partout ailleurs).
 * - rôle concerné par la méthode appelée (manager pour l'écriture ;
 *   manager OU viewer pour la lecture) : restreint à `$user->warehouses`
 *   — vide si aucun entrepôt ne lui est attribué (fail-closed : aucun
 *   accès, jamais un accès total par défaut — D1/D2 T19, reconduit à
 *   l'identique pour viewer en lecture par D1 T21).
 * - tout le reste (sans rôle, chauffeur...) : AUCUNE restriction
 *   ajoutée par ce trait, ni en écriture ni en lecture (D3 T21 : le
 *   scoping en lecture est exclusif au rôle viewer, jamais étendu aux
 *   comptes sans rôle). En pratique, HasRoleBasedAuthorization bloque
 *   déjà l'écriture pour ces profils avant qu'ils n'atteignent un point
 *   composant ce trait.
 */
trait ScopesToOwnWarehouses
{
    /**
     * Étape T19 — ÉCRITURE, manager uniquement. Comportement inchangé
     * depuis T19 (D5 T21) : n'importe quelle modification de
     * resolveScopedWarehouseIds() ci-dessous doit laisser le résultat de
     * cette méthode strictement identique pour admin/manager/sans
     * utilisateur.
     *
     * @return Collection<int, int>|null null = aucune restriction (voir
     *                                    la règle de résolution ci-dessus).
     */
    protected static function currentUserWarehouseIds(): ?Collection
    {
        return static::resolveScopedWarehouseIds(['manager']);
    }

    /**
     * Étape T21 — LECTURE, manager + viewer (D5 : méthode séparée,
     * n'affecte jamais currentUserWarehouseIds() ci-dessus). Pour
     * admin/manager/sans utilisateur, retourne exactement le même
     * résultat que currentUserWarehouseIds() — seule la branche viewer
     * change (D3 : nouvelle inclusion, exclusive à ce rôle).
     *
     * @return Collection<int, int>|null null = aucune restriction.
     */
    protected static function currentUserReadWarehouseIds(): ?Collection
    {
        return static::resolveScopedWarehouseIds(['manager', 'viewer']);
    }

    /**
     * Résolution partagée par les deux méthodes ci-dessus : seul
     * l'ensemble de rôles concernés ($rolesToScope) diffère entre
     * l'écriture (['manager']) et la lecture (['manager', 'viewer']).
     *
     * @param  array<int, string>  $rolesToScope
     * @return Collection<int, int>|null
     */
    private static function resolveScopedWarehouseIds(array $rolesToScope): ?Collection
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if ($user->hasRole('admin')) {
            return null;
        }

        if (! $user->hasAnyRole($rolesToScope)) {
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
