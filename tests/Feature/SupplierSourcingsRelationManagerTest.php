<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\SupplierSourcingsRelationManager;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.3.1 — test de caractérisation du
 * SupplierSourcingsRelationManager, ÉCRIT AVANT TOUT CODE D2.3.2/D2.3.3.
 *
 * `SupplierSourcingsRelationManager` n'existe pas encore à ce stade et
 * `ProductResource::getRelations()` ne le référence pas non plus : ce
 * fichier est donc, par construction, intégralement ROUGE tant que D2.3.2
 * (création du composant) et D2.3.3 (branchement dans ProductResource)
 * n'ont pas été réalisés — c'est le comportement attendu d'un test écrit
 * en amont du code (même discipline que D2.2/2.6.6.3). Il doit devenir
 * vert à l'identique une fois D2.3.2/D2.3.3 terminés, sans qu'aucune
 * assertion ci-dessous n'ait besoin d'être modifiée.
 *
 * Couverture des points caractérisés (validation utilisateur D2.3.1) :
 * - Le RelationManager est déclaré dans ProductResource::getRelations().
 * - Il s'appuie sur Product::supplierSourcings() (D1), jamais une requête
 *   réécrite.
 * - Il liste uniquement les fiches de sourcing du produit courant.
 * - Il permet la création d'une fiche : supplier_id requis,
 *   product_variant_id facultatif, priority/supplier_cost/currency/
 *   lead_time_days/min_order_quantity/is_active/notes pris en compte
 *   conformément au schéma de supplier_product_sourcing (D1) — aucun
 *   champ product_id dans le formulaire, rempli automatiquement par la
 *   relation.
 * - Aucune logique conditionnelle liée à `activity` : comportement
 *   identique pour les 4 activités utilisant Product (Sport, Bébé, Moto,
 *   Artisanat du Maroc — VTC exclue, n'utilise pas Product).
 * - Le niveau ProductVariantResource n'est volontairement PAS couvert ici
 *   (hors périmètre explicite de D2.3).
 */
class SupplierSourcingsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    /*
     * =================================================================
     * "il utilisera Product::supplierSourcings()"
     * =================================================================
     */
    public function test_le_relation_manager_utilise_la_relation_supplier_sourcings_du_produit(): void
    {
        $this->assertSame(
            'supplierSourcings',
            SupplierSourcingsRelationManager::getRelationshipName()
        );
    }

    /*
     * =================================================================
     * "le RelationManager sera attaché à ProductResource"
     * =================================================================
     */
    public function test_le_relation_manager_est_declare_dans_les_relations_de_product_resource(): void
    {
        $this->assertContains(
            SupplierSourcingsRelationManager::class,
            ProductResource::getRelations()
        );
    }

    /*
     * =================================================================
     * "il devra lister les sourcings appartenant au produit courant"
     * =================================================================
     */
    public function test_le_composant_liste_uniquement_les_sourcings_du_produit_courant(): void
    {
        $product = Product::factory()->create();
        $autreProduit = Product::factory()->create();
        $supplierA = Supplier::factory()->create(['name' => fake()->company()]);
        $supplierB = Supplier::factory()->create(['name' => fake()->company()]);

        $sourcingA = $product->supplierSourcings()->create(['supplier_id' => $supplierA->id]);
        $sourcingB = $product->supplierSourcings()->create(['supplier_id' => $supplierB->id]);

        // Fiche sur un AUTRE produit : ne doit jamais apparaître dans la
        // liste du composant monté sur $product.
        $sourcingAutreProduit = $autreProduit->supplierSourcings()->create(['supplier_id' => $supplierA->id]);

        Livewire::test(SupplierSourcingsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->assertCanSeeTableRecords([$sourcingA, $sourcingB])
            ->assertCanNotSeeTableRecords([$sourcingAutreProduit]);
    }

    /*
     * =================================================================
     * "supplier_id est requis"
     * =================================================================
     */
    public function test_supplier_id_est_requis_a_la_creation(): void
    {
        $product = Product::factory()->create();

        Livewire::test(SupplierSourcingsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'supplier_id' => null,
            ])
            ->assertHasTableActionErrors(['supplier_id' => 'required']);
    }

    /*
     * =================================================================
     * "product_variant_id est facultatif" — création SANS variante.
     * =================================================================
     */
    public function test_creer_une_fiche_de_sourcing_sans_variante_reussit(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        Livewire::test(SupplierSourcingsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'supplier_id' => $supplier->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'product_variant_id' => null,
        ]);
    }

    /*
     * =================================================================
     * "priority, supplier_cost, currency, lead_time_days,
     * min_order_quantity, is_active et notes doivent être pris en compte
     * conformément au schéma existant" + product_variant_id renseigné
     * (autre branche de "facultatif").
     * =================================================================
     */
    public function test_creer_une_fiche_de_sourcing_avec_tous_les_champs_du_schema(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        Livewire::test(SupplierSourcingsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'supplier_id' => $supplier->id,
                'product_variant_id' => $variant->id,
                'priority' => 10,
                'supplier_cost' => 9.90,
                'currency' => 'EUR',
                'lead_time_days' => 3,
                'min_order_quantity' => 5,
                'is_active' => false,
                'notes' => 'Fournisseur secondaire',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'supplier_id' => $supplier->id,
            'priority' => 10,
            'supplier_cost' => 9.90,
            'currency' => 'EUR',
            'lead_time_days' => 3,
            'min_order_quantity' => 5,
            'is_active' => false,
            'notes' => 'Fournisseur secondaire',
        ]);
    }

    /*
     * =================================================================
     * "aucune logique conditionnelle liée à activity" — comportement
     * identique pour les 4 activités utilisant Product.
     * =================================================================
     */
    public function test_le_comportement_est_identique_pour_les_quatre_activites_utilisant_product(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);

            Livewire::test(SupplierSourcingsRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])
                ->callTableAction('create', data: [
                    'supplier_id' => $supplier->id,
                ])
                ->assertHasNoTableActionErrors();

            $this->assertDatabaseHas('supplier_product_sourcing', [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
            ]);
        }
    }
}
