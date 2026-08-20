<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderTest extends TestCase
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
     * Création
     * =================================================================
     */

    public function test_une_commande_est_creee_en_brouillon_avec_lutilisateur_authentifie(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-1']);

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertSame($user->id, $order->user_id);
    }

    public function test_une_ligne_sur_un_produit_avec_variantes_exige_une_variante(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-2']);

        $this->expectException(\Exception::class);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
        ]);
    }

    /*
     * =================================================================
     * markAsConfirmed()
     * =================================================================
     */

    public function test_confirmer_une_commande_sans_ligne_est_rejete(): void
    {
        $order = SalesOrder::create(['reference' => 'CMD-TEST-3']);

        $this->expectException(\Exception::class);
        $order->markAsConfirmed();
    }

    public function test_confirmer_une_commande_la_fait_passer_a_confirmee(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-4']);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
        ]);

        $order->markAsConfirmed();
        $order->refresh();

        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->status);
        $this->assertNotNull($order->order_date);
    }

    /*
     * =================================================================
     * ship() — cas nominal
     * =================================================================
     */

    public function test_expedition_complete_sur_variante_genere_un_mouvement_et_decremente_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 20]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-5']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 8,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 8]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(SalesOrder::STATUS_SHIPPED, $order->status);
        $this->assertSame(8, $item->quantity_shipped);
        $this->assertSame(12, $variant->stock); // 20 - 8
        $this->assertSame(12, $product->stock);

        $movement = StockMovement::first();
        $this->assertSame('sale', $movement->type);
        $this->assertSame(8, $movement->quantity);
        $this->assertSame($order->id, $movement->sales_order_id);
    }

    public function test_expedition_complete_sur_produit_sans_variante(): void
    {
        $product = $this->makeProduct(['stock' => 15]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-6']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 5]);

        $product->refresh();
        $this->assertSame(10, $product->stock); // 15 - 5
    }

    /*
     * =================================================================
     * ship() — stock insuffisant
     * =================================================================
     */

    public function test_expedier_plus_que_le_stock_disponible_est_rejete_sans_ecriture(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 3]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-7']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            $order->ship([$item->id => 10]);
        } finally {
            $order->refresh();
            $item->refresh();
            $variant->refresh();

            $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->status);
            $this->assertSame(0, $item->quantity_shipped);
            $this->assertSame(3, $variant->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_expedition_multi_lignes_est_integralement_annulee_si_une_ligne_echoue(): void
    {
        $productA = $this->makeProduct(['reference' => 'REF-A']);
        $variantA = $this->makeVariant($productA, ['sku' => 'SKU-A', 'stock' => 20]);

        $productB = $this->makeProduct(['reference' => 'REF-B']);
        $variantB = $this->makeVariant($productB, ['sku' => 'SKU-B', 'stock' => 2]); // insuffisant pour 5

        $order = SalesOrder::create(['reference' => 'CMD-TEST-8']);
        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productA->id,
            'product_variant_id' => $variantA->id,
            'quantity_ordered' => 5,
        ]);
        $itemB = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productB->id,
            'product_variant_id' => $variantB->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            // itemA passerait (20 >= 5), itemB échoue (2 < 5) : la
            // transaction doit tout annuler, y compris itemA.
            $order->ship([$itemA->id => 5, $itemB->id => 5]);
        } finally {
            $itemA->refresh();
            $itemB->refresh();
            $variantA->refresh();
            $variantB->refresh();

            $this->assertSame(0, $itemA->quantity_shipped);
            $this->assertSame(0, $itemB->quantity_shipped);
            $this->assertSame(20, $variantA->stock);
            $this->assertSame(2, $variantB->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    /*
     * =================================================================
     * Expédition partielle
     * =================================================================
     */

    public function test_expedition_partielle_laisse_la_commande_en_partiellement_expediee(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 20]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-9']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 4]);

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_PARTIALLY_SHIPPED, $order->status);

        $order->ship([$item->id => 6]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();

        $this->assertSame(SalesOrder::STATUS_SHIPPED, $order->status);
        $this->assertSame(10, $item->quantity_shipped);
        $this->assertSame(10, $variant->stock); // 20 - 10
        $this->assertSame(2, StockMovement::count());
    }

    /*
     * =================================================================
     * cancel()
     * =================================================================
     */

    public function test_annuler_une_commande_partiellement_expediee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 20]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-10']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsConfirmed();
        $order->ship([$item->id => 3]);

        $this->expectException(\Exception::class);
        $order->cancel();
    }

    /*
     * =================================================================
     * Verrouillage / suppression
     * =================================================================
     */

    public function test_modifier_la_quantite_commandee_dune_ligne_est_rejete_hors_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-11']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);
        $item->update(['quantity_ordered' => 8]);
    }

    public function test_supprimer_une_commande_deja_expediee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-12']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();
        $order->ship([$item->id => 2]);

        $this->expectException(\Exception::class);
        $order->delete();
    }

    /*
     * =================================================================
     * Relations
     * =================================================================
     */

    public function test_les_relations_customer_et_stock_movements_fonctionnent(): void
    {
        $customer = Customer::create(['name' => 'Jean Dupont']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);

        $order = SalesOrder::create([
            'reference' => 'CMD-TEST-13',
            'customer_id' => $customer->id,
        ]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 4,
        ]);
        $order->markAsConfirmed();
        $order->ship([$item->id => 4]);

        $this->assertTrue($customer->salesOrders->contains($order));
        $this->assertSame(1, $order->stockMovements()->count());
        $this->assertSame($order->id, $order->stockMovements()->first()->salesOrder->id);
    }
}
