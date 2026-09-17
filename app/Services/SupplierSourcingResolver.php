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
     * Chantier Dropshipping, étape D2.9 — $excludeSupplierId (optionnel,
     * rétrocompatible : défaut null, comportement D2.4.6 strictement
     * inchangé pour tout appelant existant) exclut un fournisseur précis
     * du résultat, filtré EN MÉMOIRE après tri plutôt qu'en SQL : aucune
     * nouvelle règle de sélection, cela reste le même tri déjà validé,
     * simplement amputé d'un candidat.
     *
     * @return Collection<int, SupplierProductSourcing>
     */
    public function forProduct(Product $product, ?int $excludeSupplierId = null): Collection
    {
        $sourcings = $product->supplierSourcings()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return $this->rejectExcludedSupplier($sourcings, $excludeSupplierId);
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
     * Chantier Dropshipping, étape D2.9 — $excludeSupplierId (optionnel,
     * rétrocompatible) exclut un fournisseur précis, mais UNIQUEMENT
     * après la décision de palier ci-dessous : le choix "palier
     * spécifique vs repli générique" reste basé sur l'existence NON
     * FILTRÉE du palier spécifique, jamais sur son état après exclusion.
     * Exclure l'unique fournisseur spécifique actif ne doit jamais faire
     * basculer silencieusement vers le générique (violerait la règle de
     * priorité stricte déjà validée en D2.4.6) : dans ce cas, le palier
     * spécifique retourne vide, best() renverra null.
     *
     * @return Collection<int, SupplierProductSourcing>
     */
    public function forProductVariant(ProductVariant $variant, ?int $excludeSupplierId = null): Collection
    {
        $specific = $variant->supplierSourcings()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($specific->isNotEmpty()) {
            return $this->rejectExcludedSupplier($specific, $excludeSupplierId);
        }

        $generic = $variant->product->supplierSourcings()
            ->where('is_active', true)
            ->whereNull('product_variant_id')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return $this->rejectExcludedSupplier($generic, $excludeSupplierId);
    }

    /**
     * Meilleur sourcing applicable (premier élément après tri priority
     * ASC / id ASC), ou null si aucune fiche active n'est applicable.
     * Accepte indifféremment un Product ou un ProductVariant : délègue à
     * forProduct()/forProductVariant() selon le type reçu.
     *
     * Chantier Dropshipping, étape D2.9 — $excludeSupplierId (optionnel,
     * rétrocompatible : défaut null, comportement D2.4.6/D2.4.7/D2.5
     * strictement inchangé pour tout appelant existant, dont recordFor()
     * qui n'est pas modifié) permet de rechercher le meilleur fournisseur
     * ALTERNATIF compatible, en excluant le fournisseur d'une allocation
     * précédente lors d'une ré-allocation manuelle. Retourne null si
     * aucun fournisseur alternatif compatible n'est disponible — même
     * sémantique "absence de résultat légitime" que sans exclusion.
     */
    public function best(Product|ProductVariant $subject, ?int $excludeSupplierId = null): ?SupplierProductSourcing
    {
        $sourcings = $subject instanceof ProductVariant
            ? $this->forProductVariant($subject, $excludeSupplierId)
            : $this->forProduct($subject, $excludeSupplierId);

        return $sourcings->first();
    }

    /**
     * Filtre en mémoire une Collection déjà triée : retire le fournisseur
     * exclu si demandé, sans jamais retrier ni requêter à nouveau
     * (préserve l'ordre priority ASC / id ASC déjà établi). ->values()
     * réindexe pour que ->first() reste fiable après un reject().
     *
     * @param  Collection<int, SupplierProductSourcing>  $sourcings
     * @return Collection<int, SupplierProductSourcing>
     */
    private function rejectExcludedSupplier(Collection $sourcings, ?int $excludeSupplierId): Collection
    {
        if ($excludeSupplierId === null) {
            return $sourcings;
        }

        return $sourcings
            ->reject(fn (SupplierProductSourcing $sourcing): bool => $sourcing->supplier_id === $excludeSupplierId)
            ->values();
    }
}
