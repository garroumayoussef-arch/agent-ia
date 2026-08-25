<?php

namespace App\Models\Concerns;

use App\Models\Warehouse;

/**
 * Étape T13 — validation partagée de l'entrepôt d'une opération
 * (réception PurchaseOrder::receive() / expédition SalesOrder::ship()),
 * un seul entrepôt pour toute l'opération, jamais par ligne.
 *
 * Composé par PurchaseOrder et SalesOrder plutôt que dupliqué : règle
 * identique dans les deux sens (achat/vente), même esprit que les
 * traits déjà utilisés ailleurs dans ce projet (ScopesToOwnDriver,
 * HasRoleBasedAuthorization).
 *
 * Ne crée aucune nouvelle source de vérité sur l'entrepôt : ne stocke
 * rien sur le modèle qui compose ce trait, se contente de valider la
 * valeur avant qu'elle soit transmise à StockMovement::create().
 */
trait ValidatesOperationWarehouse
{
    /**
     * Valide l'entrepôt d'une opération de réception/expédition.
     *
     * Règle impérative : aucun entrepôt n'est jamais choisi
     * silencieusement dès lors qu'un choix réel existe.
     * - $warehouseId fourni : doit référencer un entrepôt existant ET
     *   actif — jamais confiance dans une valeur venue du navigateur.
     * - $warehouseId absent : autorisé uniquement s'il n'y a aucune
     *   ambiguïté (0 ou 1 entrepôt actif — dans ce cas,
     *   StockMovement::creating() résout lui-même l'entrepôt par
     *   défaut, comportement T11b inchangé). Dès que 2 entrepôts actifs
     *   ou plus existent, une sélection explicite est obligatoire.
     */
    protected static function assertValidOperationWarehouse(?int $warehouseId): void
    {
        if ($warehouseId !== null) {
            if (!Warehouse::where('id', $warehouseId)->where('is_active', true)->exists()) {
                throw new \Exception(
                    "L'entrepôt sélectionné n'existe pas ou n'est plus actif."
                );
            }

            return;
        }

        if (Warehouse::where('is_active', true)->count() > 1) {
            throw new \Exception(
                'Plusieurs entrepôts sont disponibles : veuillez sélectionner explicitement l\'entrepôt concerné.'
            );
        }
    }
}
