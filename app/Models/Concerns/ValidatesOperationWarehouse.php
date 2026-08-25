<?php

namespace App\Models\Concerns;

use App\Filament\Concerns\ScopesToOwnWarehouses;
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
 *
 * Étape T19 — s'appuie en plus sur ScopesToOwnWarehouses pour vérifier
 * que l'entrepôt EFFECTIF de l'opération (fourni explicitement, ou
 * résolu implicitement ci-dessous quand aucune ambiguïté n'existe)
 * appartient au périmètre de l'utilisateur courant. Contrôle
 * d'autorisation uniquement : la logique métier T13 (existence, statut
 * actif, ambiguïté) reste strictement inchangée au-dessus.
 */
trait ValidatesOperationWarehouse
{
    use ScopesToOwnWarehouses;

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
        } elseif (Warehouse::where('is_active', true)->count() > 1) {
            throw new \Exception(
                'Plusieurs entrepôts sont disponibles : veuillez sélectionner explicitement l\'entrepôt concerné.'
            );
        }

        /*
         * T19 — contrôle d'appartenance, en plus du contrôle
         * d'existence ci-dessus. Résout l'entrepôt EFFECTIF de la même
         * façon que StockMovement::creating() le fera ensuite (celui
         * fourni, ou l'entrepôt marqué par défaut si aucun n'est
         * fourni) : un manager restreint ne peut pas contourner son
         * périmètre simplement en laissant $warehouseId vide quand un
         * seul entrepôt actif existe.
         */
        static::assertWarehouseIsInScope(
            $warehouseId ?? Warehouse::where('is_default', true)->value('id')
        );
    }
}
