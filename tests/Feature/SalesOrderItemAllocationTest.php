<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\SupplierProductSourcing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.4.7 — couverture de
 * SalesOrderItemAllocation::recordFor(), première capacité d'ÉCRITURE du
 * chantier. Aucune interface Filament, aucune autorisation en jeu ici :
 * tests au niveau modèle uniquement, comme SupplierSourcingResolverTest
 * (D2.4.6) ou SupplierProductSourcingTest (D1).
 *
 * Couvre les règles métier validées (manifeste D2.4.7) : délégation
 * exclusive à SupplierSourcingResolver::best() (aucune nouvelle règle de
 * sélection), une allocation par ligne (rejet explicite d'une seconde
 * tentative), échec explicite (jamais silencieux) en l'absence de
 * sourcing actif, contraintes restrictOnDelete, transversalité
 * inter-activités, et absence de tout effet de bord sur
 * PurchaseOrder/StockMovement/stock.
 */
class SalesOrderItemAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function createSourcing(Product $product, array $overrides = []): SupplierProductSourcing
    {
        return $product->supplierSourcings()->create(array_merge([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ], $overrides));
    }

    /*
     * =================================================================
     * recordFor() — sourcing actif unique
     * =================================================================
     */

    public function test_recordfor_alloue_au_sourcing_actif_du_produit(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $sourcing = $this->createSourcing($product);

        $allocation = SalesOrderItemAllocation::recordFor($item);

        $this->assertSame($item->id, $allocation->sales_order_item_id);
        $this->assertSame($sourcing->id, $allocation->supplier_product_sourcing_id);
        $this->assertSame($item->quantity_ordered, $allocation->quantity);
    }

    /*
     * =================================================================
     * recordFor() — priorité stricte au sourcing spécifique de la
     * variante (délégation exclusive au comportement déjà testé du
     * resolver, D2.4.6)
     * =================================================================
     */

    public function test_recordfor_alloue_au_sourcing_specifique_de_la_variante_en_priorite(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $item = SalesOrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        // Sourcing générique du produit : actif, mais ne doit PAS être
        // choisi puisqu'un sourcing spécifique actif existe.
        $this->createSourcing($product);

        $sourcingVariante = $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $allocation = SalesOrderItemAllocation::recordFor($item);

        $this->assertSame($sourcingVariante->id, $allocation->supplier_product_sourcing_id);
    }

    /*
     * =================================================================
     * recordFor() — aucun sourcing actif applicable : échec explicite,
     * jamais un retour null silencieux
     * =================================================================
     */

    public function test_recordfor_leve_une_exception_si_aucun_sourcing_actif_nest_applicable(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Aucun sourcing fournisseur actif n'est applicable à cette ligne.");

        SalesOrderItemAllocation::recordFor($item);
    }

    /*
     * =================================================================
     * recordFor() — une seule allocation par ligne (split multi-
     * fournisseur et réallocation automatique hors périmètre)
     * =================================================================
     */

    public function test_recordfor_rejette_une_seconde_allocation_sur_la_meme_ligne(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette ligne de commande a déjà une allocation.');

        SalesOrderItemAllocation::recordFor($item);
    }

    /*
     * =================================================================
     * Contraintes de suppression (restrictOnDelete)
     * =================================================================
     */

    public function test_suppression_du_sourcing_reference_par_une_allocation_est_bloquee(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $sourcing = $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->expectException(QueryException::class);

        $sourcing->delete();
    }

    public function test_suppression_dune_sales_order_item_allouee_est_bloquee(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        // quantity_shipped = 0 ici : c'est bien la contrainte de base
        // restrictOnDelete qui est testée, pas le garde-fou applicatif
        // de SalesOrderItem::deleting() (ligne déjà expédiée).
        $this->expectException(QueryException::class);

        $item->delete();
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
            $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
            $sourcing = $this->createSourcing($product);

            $allocation = SalesOrderItemAllocation::recordFor($item);

            $this->assertSame($sourcing->id, $allocation->supplier_product_sourcing_id);
        }
    }

    /*
     * =================================================================
     * Absence d'effet de bord — aucun StockMovement/PurchaseOrder, aucun
     * changement de stock
     * =================================================================
     */

    public function test_recordfor_ne_produit_aucun_effet_de_bord_sur_stock_ou_commandes(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }
}
