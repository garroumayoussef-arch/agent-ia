<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
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

    public function test_un_bon_de_commande_est_cree_en_brouillon_avec_lutilisateur_authentifie(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-1']);

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);
        $this->assertSame($user->id, $order->user_id);
    }

    public function test_une_ligne_sur_un_produit_avec_variantes_exige_une_variante(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-2']);

        $this->expectException(\Exception::class);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
        ]);
    }

    public function test_une_ligne_sur_un_produit_sans_variante_est_acceptee(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-3']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
        ]);

        $this->assertNotNull($item->id);
        $this->assertSame(0, $item->quantity_received);
    }

    /*
     * =================================================================
     * markAsOrdered()
     * =================================================================
     */

    public function test_confirmer_un_bon_de_commande_sans_ligne_est_rejete(): void
    {
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-4']);

        $this->expectException(\Exception::class);
        $order->markAsOrdered();
    }

    public function test_confirmer_un_bon_de_commande_le_fait_passer_a_commande(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-5']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $order->markAsOrdered();
        $order->refresh();

        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->status);
        $this->assertNotNull($order->order_date);
    }

    public function test_confirmer_un_bon_de_commande_deja_commande_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-6']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);
        $order->markAsOrdered();
    }

    /*
     * =================================================================
     * receive() — cas nominal, variante
     * =================================================================
     */

    public function test_reception_complete_sur_variante_genere_un_mouvement_et_synchronise_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 3]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-7']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 10]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertSame(10, $item->quantity_received);
        $this->assertSame(13, $variant->stock); // 3 + 10
        $this->assertSame(13, $product->stock);

        $movement = StockMovement::first();
        $this->assertNotNull($movement);
        $this->assertSame('purchase', $movement->type);
        $this->assertSame(10, $movement->quantity);
        $this->assertSame($order->id, $movement->purchase_order_id);
        $this->assertSame($variant->id, $movement->product_variant_id);
    }

    public function test_reception_complete_sur_produit_sans_variante(): void
    {
        $product = $this->makeProduct(['stock' => 2]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-8']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 6,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 6]);

        $product->refresh();
        $item->refresh();

        $this->assertSame(8, $product->stock); // 2 + 6
        $this->assertSame(6, $item->quantity_received);
    }

    /*
     * =================================================================
     * receive() — réception partielle
     * =================================================================
     */

    public function test_reception_partielle_laisse_le_bon_en_partiellement_recu(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-9']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 4]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();

        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->status);
        $this->assertSame(4, $item->quantity_received);
        $this->assertSame(4, $variant->stock);

        // Seconde réception : solde le reste.
        $order->receive([$item->id => 6]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertSame(10, $item->quantity_received);
        $this->assertSame(10, $variant->stock);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_recevoir_plus_que_la_quantite_restante_est_rejete_sans_ecriture(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-10']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);

        try {
            $order->receive([$item->id => 8]);
        } finally {
            $order->refresh();
            $item->refresh();
            $variant->refresh();

            $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->status);
            $this->assertSame(0, $item->quantity_received);
            $this->assertSame(0, $variant->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_receptionner_un_bon_en_brouillon_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-11']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $this->expectException(\Exception::class);
        $order->receive([$item->id => 5]);
    }

    public function test_receptionner_un_bon_annule_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-12']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();
        $order->cancel();

        $this->expectException(\Exception::class);
        $order->receive([$item->id => 5]);
    }

    /*
     * =================================================================
     * cancel()
     * =================================================================
     */

    public function test_annuler_un_bon_en_brouillon(): void
    {
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-13']);
        $order->cancel();

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $order->status);
    }

    public function test_annuler_un_bon_partiellement_recu_est_rejete(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-14']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsOrdered();
        $order->receive([$item->id => 2]);

        $this->expectException(\Exception::class);
        $order->cancel();
    }

    /*
     * =================================================================
     * Verrouillage des lignes hors brouillon
     * =================================================================
     */

    public function test_une_ligne_peut_etre_modifiee_tant_que_le_bon_est_en_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-15']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $item->update(['quantity_ordered' => 8]);

        $this->assertSame(8, $item->fresh()->quantity_ordered);
    }

    public function test_modifier_la_quantite_commandee_dune_ligne_est_rejete_hors_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-16']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);
        $item->update(['quantity_ordered' => 8]);
    }

    /*
     * =================================================================
     * Suppression
     * =================================================================
     */

    public function test_supprimer_une_ligne_non_receptionnee_fonctionne(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-17']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $item->delete();

        $this->assertSame(0, PurchaseOrderItem::count());
    }

    public function test_supprimer_une_ligne_deja_receptionnee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-18']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();
        $order->receive([$item->id => 2]);

        // receive() opère sur une copie fraîchement chargée en interne :
        // on rafraîchit l'instance de test pour voir quantity_received à jour.
        $item->refresh();

        $this->expectException(\Exception::class);
        $item->delete();
    }

    public function test_supprimer_un_bon_de_commande_deja_receptionne_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-19']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();
        $order->receive([$item->id => 2]);

        $this->expectException(\Exception::class);
        $order->delete();
    }

    public function test_supprimer_un_bon_de_commande_en_brouillon_supprime_ses_lignes(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-20']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $order->delete();

        $this->assertSame(0, PurchaseOrderItem::count());
    }

    /*
     * =================================================================
     * Relations
     * =================================================================
     */

    public function test_les_relations_supplier_et_stock_movements_fonctionnent(): void
    {
        $supplier = Supplier::create(['name' => 'AliExpress']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $order = PurchaseOrder::create([
            'reference' => 'BC-TEST-21',
            'supplier_id' => $supplier->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();
        $order->receive([$item->id => 5]);

        $this->assertTrue($supplier->purchaseOrders->contains($order));
        $this->assertSame(1, $order->stockMovements()->count());
        $this->assertSame($order->id, $order->stockMovements()->first()->purchaseOrder->id);
    }
}
