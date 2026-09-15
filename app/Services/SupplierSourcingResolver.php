<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierProductSourcing;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chantier Dropshipping, étape D2.4.6 — première capacité de LECTURE
 * exploitant supplier_product_sourcing (D1) : détermine, de façon
 * déterministe, le(s) sourcing(s) fournisseur actif(s) applicable(s) à un
 * Product ou un ProductVariant. Capacité Core transversale : aucune
 * référence à `activity` nulle part dans ce fichier, comportement
 * strictement identique pour toutes les activités utilisant Product
 * (Sport, Bébé, Moto, Artisanat du Maroc, Dropshipping...).
 *
 * S'appuie EXCLUSIVEMENT sur les relations Eloquent déjà existantes
 * Product::supplierSourcings() et ProductVariant::supplierSourcings()
 * (étape D1) : aucune nouvelle relation, aucune requête SQL brute, aucun
 * scope ajouté sur SupplierProductSourcing.
 *
 * Première classe de type "service" du dépôt (aucun répertoire
 * app/Services n'existait avant cette étape) : namespace volontairement
 * neutre (pas de sous-namespace "Dropshipping"), pour ne jamais laisser
 * penser que cette capacité Core serait réservée à l'activité métier
 * Dropshipping — distincte du chantier d'ingénierie du même nom.
 *
 * Hors périmètre (délibérément absent de ce fichier) : toute écriture
 * (PurchaseOrder, SalesOrder, StockMovement), sélection automatique
 * déclenchant une action, allocation, expédition, appel API, interface
 * Filament, Policy Laravel — pure lecture, aucun effet de bord.
 */
class SupplierSourcingResolver
{
    /**
     * Fiches actives (is_active = true) du produit, triées par priorité
     * croissante (priority ASC), égalité départagée de façon déterministe
     * par id ASC (la fiche créée en premier gagne). Inclut, sans
     * distinction, aussi bien les fiches génériques
     * (product_variant_id NULL) que les fiches spécifiques à l'une de ses
     * variantes — comportement natif de Product::supplierSourcings()
     * (D1), non retraité ici.
     *
     * @return Collection<int, SupplierProductSourcing>
     */
    public function forProduct(Product $product): Collection
    {
        return $product->supplierSourcings()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Fiches actives applicables à cette variante précise, triées de la
     * même façon (priority ASC, id ASC). Priorité stricte au niveau
     * spécifique : si au moins une fiche active existe avec
     * product_variant_id = cette variante, retourne EXCLUSIVEMENT
     * ces fiches-là — jamais mélangées avec le niveau produit générique,
     * pour éviter toute ambiguïté de priorité entre deux échelles
     * différentes. Sinon (aucune fiche spécifique active), repli
     * déterministe vers les fiches actives génériques
     * (product_variant_id IS NULL) du Product parent.
     *
     * @return Collection<int, SupplierProductSourcing>
     */
    public function forProductVariant(ProductVariant $variant): Collection
    {
        $specific = $variant->supplierSourcings()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($specific->isNotEmpty()) {
            return $specific;
        }

        return $variant->product->supplierSourcings()
            ->where('is_active', true)
            ->whereNull('product_variant_id')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Meilleur sourcing applicable (premier élément après tri priority
     * ASC / id ASC), ou null si aucune fiche active n'est applicable.
     * Accepte indifféremment un Product ou un ProductVariant : délègue à
     * forProduct()/forProductVariant() selon le type reçu.
     */
    public function best(Product|ProductVariant $subject): ?SupplierProductSourcing
    {
        $sourcings = $subject instanceof ProductVariant
            ? $this->forProductVariant($subject)
            : $this->forProduct($subject);

        return $sourcings->first();
    }
}
