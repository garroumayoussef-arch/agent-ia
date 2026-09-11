<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductSourcing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D1 — couvre exclusivement ce que D1
 * introduit réellement : la table supplier_product_sourcing, ses
 * relations, sa double garantie d'unicité (avec/sans variante) et la
 * garde de suppression sur Supplier (restrictOnDelete). Aucun test ici
 * ne touche SalesOrder/PurchaseOrder/StockMovement — hors périmètre de
 * D1.
 */
class SupplierProductSourcingTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_fiche_de_sourcing_peut_etre_creee_pour_un_produit_sans_variante(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'supplier_cost' => 12.50,
            'lead_time_days' => 5,
        ]);

        // priority/is_active ne sont pas passés explicitement : leur
        // valeur ne vient que du défaut SQL de la migration, jamais
        // rejoué côté PHP après un create() (même précaution que
        // Product::syncAttributeMirror(), cf. sa documentation) — on
        // relit donc l'état réellement persisté.
        $sourcing->refresh();

        $this->assertSame(100, $sourcing->priority);
        $this->assertTrue($sourcing->is_active);
        $this->assertSame('12.50', (string) $sourcing->supplier_cost);
    }

    public function test_une_fiche_de_sourcing_peut_etre_creee_pour_une_variante_precise(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->assertSame($variant->id, $sourcing->product_variant_id);
    }

    public function test_priority_et_is_active_sont_surchargeables(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'priority' => 10,
            'is_active' => false,
        ]);

        $this->assertSame(10, $sourcing->priority);
        $this->assertFalse($sourcing->is_active);
    }

    /**
     * Cas "avec variante" : la contrainte UNIQUE composée classique
     * suffit seule (aucun NULL en jeu).
     */
    public function test_deux_fiches_identiques_avec_la_meme_variante_sont_rejetees(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->expectException(QueryException::class);
        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    /**
     * Cas "sans variante" — celui que la seule contrainte UNIQUE
     * composée ne couvrirait PAS (NULL jamais égal à NULL en SQL
     * standard) : c'est l'index UNIQUE PARTIEL qui doit bloquer ce
     * doublon. Sans lui, cette création réussirait à tort.
     */
    public function test_deux_fiches_identiques_sans_variante_sont_rejetees(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);

        $this->expectException(QueryException::class);
        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);
    }

    /**
     * Une fiche "sans variante" et une fiche "avec variante" pour le même
     * couple fournisseur/produit ne se gênent pas : les deux garanties
     * d'unicité sont bien disjointes.
     */
    public function test_une_fiche_sans_variante_et_une_fiche_avec_variante_coexistent(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);

        $second = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->assertSame($variant->id, $second->product_variant_id);
        $this->assertSame(2, SupplierProductSourcing::count());
    }

    /**
     * Deux fournisseurs différents, même produit, tous deux sans
     * variante : l'index partiel porte sur (supplier_id, product_id)
     * ensemble, pas sur product_id seul.
     */
    public function test_deux_fournisseurs_differents_peuvent_sourcer_le_meme_produit_sans_variante(): void
    {
        $supplierA = Supplier::factory()->create(['name' => fake()->company()]);
        $supplierB = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        SupplierProductSourcing::create([
            'supplier_id' => $supplierA->id,
            'product_id' => $product->id,
        ]);

        $second = SupplierProductSourcing::create([
            'supplier_id' => $supplierB->id,
            'product_id' => $product->id,
        ]);

        $this->assertSame($supplierB->id, $second->supplier_id);
        $this->assertSame(2, SupplierProductSourcing::count());
    }

    public function test_la_suppression_du_produit_supprime_en_cascade_sa_fiche_de_sourcing(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
        ]);

        $product->delete();

        $this->assertDatabaseMissing('supplier_product_sourcing', ['id' => $sourcing->id]);
    }

    public function test_la_suppression_de_la_variante_supprime_en_cascade_sa_fiche_de_sourcing(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $variant->delete();

        $this->assertDatabaseMissing('supplier_product_sourcing', ['id' => $sourcing->id]);
    }

    /**
     * Décision validée : restrictOnDelete() sur supplier_id — un
     * fournisseur ayant au moins une fiche de sourcing ne peut pas être
     * supprimé.
     */
    public function test_un_fournisseur_avec_une_fiche_de_sourcing_ne_peut_pas_etre_supprime(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();

        SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
        ]);

        $this->expectException(QueryException::class);
        $supplier->delete();
    }

    public function test_les_relations_supplier_product_et_variant_fonctionnent(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $sourcing = SupplierProductSourcing::create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->assertTrue($supplier->productSourcings->contains($sourcing));
        $this->assertTrue($product->supplierSourcings->contains($sourcing));
        $this->assertTrue($variant->supplierSourcings->contains($sourcing));
        $this->assertTrue($sourcing->supplier->is($supplier));
        $this->assertTrue($sourcing->product->is($product));
        $this->assertTrue($sourcing->productVariant->is($variant));
    }
}
