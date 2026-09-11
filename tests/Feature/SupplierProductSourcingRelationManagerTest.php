<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductSourcing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.2 — test de caractérisation, ÉCRIT AVANT
 * TOUT CODE D2.3 (aucun RelationManager n'existe encore à ce stade).
 *
 * Ce test ne peut pas exercer un composant Livewire "RelationManager" côté
 * ProductResource : cette classe n'existe pas encore (D2.3, hors périmètre
 * de D2.2), et y référencer un `Livewire::test(<ClasseInexistante>::class)`
 * échouerait par une erreur fatale de classe introuvable, pas par une
 * assertion rouge propre exploitable.
 *
 * Il caractérise donc le comportement au niveau de la relation Eloquent
 * DÉJÀ existante `Product::supplierSourcings()` (Chantier Dropshipping,
 * étape D1) : c'est exactement, et uniquement, ce sur quoi le futur
 * RelationManager (D2.3) devra s'appuyer, sans réimplémenter de logique
 * métier propre (cf. manifeste D2.2 point 7). Ce test doit rester vert à
 * l'identique avant ET après l'introduction du RelationManager en D2.3 —
 * c'est la garantie de non-régression attendue.
 *
 * Couverture des points demandés (manifeste D2.2) :
 * 1. Le futur RelationManager concerne SupplierProductSourcing.
 * 2. Il s'appuie sur Product::supplierSourcings() existante, jamais une
 *    requête réécrite.
 * 3. Listage des fiches de sourcing d'un produit.
 * 4. Création d'une fiche de sourcing liée au produit via cette relation.
 * 5-6. Aucune logique spécifique à Sport : comportement identique pour les
 *    4 activités qui utilisent Product (Sport, Bébé, Moto, Artisanat du
 *    Maroc — VTC exclue, elle n'utilise pas Product), et absence de toute
 *    colonne `activity` sur la table pivot elle-même.
 * 7. Utilisation exclusive de relations déjà existantes.
 */
class SupplierProductSourcingRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    /*
     * =================================================================
     * Points 1, 2, 3 — le futur RelationManager listera des
     * SupplierProductSourcing via Product::supplierSourcings(), scopés
     * au seul produit concerné.
     * =================================================================
     */
    public function test_supplier_sourcings_du_produit_liste_uniquement_les_fiches_de_ce_produit(): void
    {
        $product = Product::factory()->create();
        $autreProduit = Product::factory()->create();
        $supplierA = Supplier::factory()->create(['name' => fake()->company()]);
        $supplierB = Supplier::factory()->create(['name' => fake()->company()]);

        $product->supplierSourcings()->create([
            'supplier_id' => $supplierA->id,
        ]);
        $product->supplierSourcings()->create([
            'supplier_id' => $supplierB->id,
        ]);

        // Fiche sur un AUTRE produit : ne doit jamais apparaître dans la
        // liste du premier — c'est précisément ce que la relation doit
        // garantir pour le futur RelationManager.
        $autreProduit->supplierSourcings()->create([
            'supplier_id' => $supplierA->id,
        ]);

        $sourcings = $product->supplierSourcings()->get();

        $this->assertCount(2, $sourcings);
        $this->assertContainsOnlyInstancesOf(SupplierProductSourcing::class, $sourcings);
        $this->assertTrue($sourcings->every(fn ($s) => $s->product_id === $product->id));
    }

    /*
     * =================================================================
     * Point 4 — création d'une fiche de sourcing liée au produit,
     * exclusivement via la relation (pas de product_id passé à la main).
     * =================================================================
     */
    public function test_creer_une_fiche_de_sourcing_via_la_relation_la_lie_automatiquement_au_produit(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        $sourcing = $product->supplierSourcings()->create([
            'supplier_id' => $supplier->id,
            'supplier_cost' => 9.90,
            'lead_time_days' => 3,
        ]);

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'id' => $sourcing->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);
        $this->assertTrue($sourcing->product->is($product));
    }

    /*
     * =================================================================
     * Points 5, 6 — transversalité : comportement identique quelle que
     * soit l'activité du produit, aucune activité traitée comme
     * centrale ou par défaut.
     * =================================================================
     */
    public function test_le_sourcing_fonctionne_a_l_identique_pour_les_quatre_activites_utilisant_product(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);

            $sourcing = $product->supplierSourcings()->create([
                'supplier_id' => $supplier->id,
            ]);

            $this->assertSame($product->id, $sourcing->product_id);
            $this->assertCount(1, $product->supplierSourcings()->get());
        }
    }

    public function test_la_table_pivot_ne_porte_aucune_colonne_activity(): void
    {
        // Garantie structurelle, pas seulement comportementale : aucune
        // colonne `activity` sur supplier_product_sourcing (cf. migration
        // D1) — le futur RelationManager ne pourra donc physiquement pas
        // introduire de filtre ou de logique propre à une activité sur
        // cette table.
        $this->assertFalse(Schema::hasColumn('supplier_product_sourcing', 'activity'));
    }

    /*
     * =================================================================
     * Point 7 — aucune nouvelle logique métier : le comptage/listage
     * repose entièrement sur la relation existante, jamais sur une
     * requête reconstruite manuellement.
     * =================================================================
     */
    public function test_le_comptage_des_sourcings_repose_sur_la_relation_existante_sans_requete_dediee(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        $product->supplierSourcings()->create(['supplier_id' => $supplier->id]);

        // Deux chemins d'accès différents à LA MÊME relation doivent
        // s'accorder : la relation chargée (Collection) et la requête
        // (Builder) issues de Product::supplierSourcings() ne divergent
        // jamais, par construction Eloquent — aucune duplication de
        // logique de filtrage ne doit exister ailleurs.
        $this->assertSame(
            $product->supplierSourcings()->count(),
            $product->supplierSourcings->count()
        );
    }
}
