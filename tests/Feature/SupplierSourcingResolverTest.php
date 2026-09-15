<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\SupplierSourcingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.4.6 — couverture de
 * SupplierSourcingResolver, première capacité de LECTURE exploitant
 * supplier_product_sourcing (D1). Aucune interface Filament, aucune
 * autorisation en jeu ici : tests au niveau service/modèle uniquement,
 * comme SupplierProductSourcingTest (D1) ou
 * SupplierProductSourcingRelationManagerTest (D2.2).
 *
 * Couvre les règles métier validées (manifeste D2.4.6) : filtrage
 * is_active, tri priority ASC puis id ASC, priorité stricte de la
 * variante sur le produit générique (jamais de mélange), repli
 * déterministe vers le produit parent en l'absence de sourcing
 * spécifique actif, et transversalité inter-activités.
 */
class SupplierSourcingResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): SupplierSourcingResolver
    {
        return new SupplierSourcingResolver;
    }

    /*
     * =================================================================
     * forProduct() — filtrage is_active + tri
     * =================================================================
     */

    public function test_for_product_exclut_les_fiches_inactives(): void
    {
        $product = Product::factory()->create();

        $active = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => false,
        ]);

        $result = $this->resolver()->forProduct($product);

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $active->id));
    }

    public function test_for_product_trie_par_priority_puis_id(): void
    {
        $product = Product::factory()->create();

        $priority10 = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        $priority5PremierCree = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 5,
            'is_active' => true,
        ]);
        $priority5DeuxiemeCree = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 5,
            'is_active' => true,
        ]);

        $result = $this->resolver()->forProduct($product);

        $this->assertSame(
            [$priority5PremierCree->id, $priority5DeuxiemeCree->id, $priority10->id],
            $result->pluck('id')->all()
        );
    }

    /*
     * =================================================================
     * forProductVariant() — priorité stricte variante, sans mélange
     * =================================================================
     */

    public function test_variante_avec_sourcing_specifique_actif_ne_retourne_que_celui_ci(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $sourcingVariante = $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        // Sourcing générique du produit parent : actif, mais NE DOIT PAS
        // apparaître puisqu'un sourcing spécifique actif existe.
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_variant_id' => null,
            'is_active' => true,
        ]);

        $result = $this->resolver()->forProductVariant($variant);

        $this->assertCount(1, $result);
        $this->assertSame($sourcingVariante->id, $result->first()->id);
    }

    public function test_aucun_melange_entre_sourcing_variante_et_sourcing_produit_parent(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 50,
            'is_active' => true,
        ]);

        $sourcingGenerique = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_variant_id' => null,
            'priority' => 1, // plus prioritaire en valeur, mais hors périmètre : ne doit jamais apparaître ici
            'is_active' => true,
        ]);

        $result = $this->resolver()->forProductVariant($variant);

        $this->assertFalse($result->contains('id', $sourcingGenerique->id));
    }

    public function test_repli_vers_le_produit_parent_quand_la_variante_na_aucun_sourcing_actif(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        // Sourcing spécifique existant mais INACTIF : ne doit pas empêcher le repli.
        $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => false,
        ]);

        $sourcingGenerique = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_variant_id' => null,
            'is_active' => true,
        ]);

        $result = $this->resolver()->forProductVariant($variant);

        $this->assertCount(1, $result);
        $this->assertSame($sourcingGenerique->id, $result->first()->id);
    }

    public function test_repli_produit_parent_trie_aussi_par_priority_puis_id(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $generique10 = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_variant_id' => null,
            'priority' => 10,
            'is_active' => true,
        ]);
        $generique5 = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_variant_id' => null,
            'priority' => 5,
            'is_active' => true,
        ]);

        $result = $this->resolver()->forProductVariant($variant);

        $this->assertSame([$generique5->id, $generique10->id], $result->pluck('id')->all());
    }

    /*
     * =================================================================
     * best()
     * =================================================================
     */

    public function test_best_retourne_le_premier_sourcing_applicable_pour_un_produit(): void
    {
        $product = Product::factory()->create();

        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 20,
            'is_active' => true,
        ]);
        $meilleur = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($meilleur->id, $this->resolver()->best($product)->id);
    }

    public function test_best_retourne_le_premier_sourcing_applicable_pour_une_variante(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 20,
            'is_active' => true,
        ]);
        $meilleur = $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($meilleur->id, $this->resolver()->best($variant)->id);
    }

    public function test_best_retourne_null_quand_aucun_sourcing_nest_applicable(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->assertNull($this->resolver()->best($product));
        $this->assertNull($this->resolver()->best($variant));
    }

    /*
     * =================================================================
     * Transversalité — aucune logique conditionnelle liée à `activity`
     * =================================================================
     */

    public function test_le_comportement_est_identique_pour_plusieurs_activites_utilisant_product(): void
    {
        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);
            $sourcing = $product->supplierSourcings()->create([
                'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
                'is_active' => true,
            ]);

            $best = $this->resolver()->best($product);

            $this->assertNotNull($best);
            $this->assertSame($sourcing->id, $best->id);
        }
    }
}
