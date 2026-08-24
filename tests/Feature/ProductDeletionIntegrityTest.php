<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product/ProductVariant sont référencés par cascadeOnDelete (stock_movements,
 * purchase_order_items, sales_order_items) ou nullOnDelete (variantes) :
 * ces tests vérifient que le modèle refuse la suppression tant qu'un
 * historique existe, plutôt que de laisser la base de données le détruire
 * silencieusement.
 */
class ProductDeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Test',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    /*
     * =================================================================
     * Cas normal : suppression autorisée sans historique
     * =================================================================
     */

    public function test_un_produit_sans_historique_peut_etre_supprime(): void
    {
        $product = $this->makeProduct();

        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_une_variante_sans_historique_peut_etre_supprimee(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $variant->delete();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
    }

    /*
     * =================================================================
     * Produit protégé par un historique de mouvements de stock
     * =================================================================
     */

    public function test_un_produit_avec_historique_de_mouvements_de_stock_ne_peut_pas_etre_supprime(): void
    {
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        $product->delete();
    }

    public function test_une_variante_avec_historique_de_mouvements_de_stock_ne_peut_pas_etre_supprimee(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        $variant->delete();
    }

    /*
     * =================================================================
     * Produit/variante référencés par un bon de commande fournisseur
     * =================================================================
     */

    public function test_un_produit_reference_par_un_bon_de_commande_ne_peut_pas_etre_supprime(): void
    {
        $product = $this->makeProduct();

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-DEL-1']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
        ]);

        $this->expectException(\Exception::class);

        $product->delete();
    }

    public function test_une_variante_referencee_par_un_bon_de_commande_ne_peut_pas_etre_supprimee(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-DEL-2']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);

        $this->expectException(\Exception::class);

        $variant->delete();
    }

    /*
     * =================================================================
     * Produit/variante référencés par une commande client
     * =================================================================
     */

    public function test_un_produit_reference_par_une_commande_client_ne_peut_pas_etre_supprime(): void
    {
        $product = $this->makeProduct();

        $order = SalesOrder::create(['reference' => 'CMD-TEST-DEL-1']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
        ]);

        $this->expectException(\Exception::class);

        $product->delete();
    }

    public function test_une_variante_referencee_par_une_commande_client_ne_peut_pas_etre_supprimee(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-DEL-2']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 2,
        ]);

        $this->expectException(\Exception::class);

        $variant->delete();
    }
}
