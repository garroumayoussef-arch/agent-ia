<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Étape T11b : StockMovement::creating() (déclenché par
     * PurchaseOrder::receive()) résout désormais systématiquement un
     * entrepôt (par défaut en repli) — un entrepôt par défaut doit
     * donc exister.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::create([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ]);
    }

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

    /*
     * =================================================================
     * Sous-total (subtotal)
     * =================================================================
     */

    public function test_le_sous_total_dune_ligne_est_calcule_automatiquement_a_la_creation(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-22']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 12.50,
        ]);

        $this->assertSame('62.50', $item->subtotal);
    }

    public function test_le_sous_total_est_recalcule_quand_la_quantite_ou_le_prix_change_en_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-23']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $item->subtotal);

        $item->update(['quantity_ordered' => 8]);
        $this->assertSame('80.00', $item->fresh()->subtotal);

        $item->update(['unit_price' => 15]);
        $this->assertSame('120.00', $item->fresh()->subtotal);
    }

    public function test_le_sous_total_est_nul_si_aucun_prix_unitaire_nest_renseigne(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-24']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $this->assertNull($item->unit_price);
        $this->assertNull($item->subtotal);
    }

    public function test_le_sous_total_est_fige_apres_confirmation_et_receive_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-25']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $item->subtotal);

        $order->markAsOrdered();
        $order->receive([$item->id => 3]);

        $item->refresh();

        // receive() ne modifie que quantity_received : le sous-total
        // figé à la confirmation ne doit pas bouger.
        $this->assertSame(3, $item->quantity_received);
        $this->assertSame('50.00', $item->subtotal);
    }

    public function test_modifier_le_prix_unitaire_dune_ligne_est_rejete_hors_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-26']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);
        $item->update(['unit_price' => 20]);
    }

    public function test_supprimer_une_ligne_ne_touche_pas_le_sous_total_des_autres_lignes(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-27']);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 10,
        ]);
        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
            'unit_price' => 20,
        ]);

        $itemA->delete();

        $this->assertSame(1, PurchaseOrderItem::count());
        $this->assertSame('60.00', $itemB->fresh()->subtotal);
    }

    /*
     * =================================================================
     * Total de la commande (total)
     * =================================================================
     */

    public function test_le_total_de_la_commande_est_calcule_a_lajout_dune_ligne(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-28']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $this->assertSame('50.00', $order->fresh()->total);
    }

    public function test_le_total_de_la_commande_est_recalcule_a_lajout_dune_deuxieme_ligne(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-29']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 25,
        ]);

        $this->assertSame('100.00', $order->fresh()->total);
    }

    public function test_le_total_de_la_commande_est_recalcule_quand_une_ligne_est_modifiee_en_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-30']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        $item->update(['quantity_ordered' => 8]);

        $this->assertSame('80.00', $order->fresh()->total);
    }

    public function test_le_total_de_la_commande_est_recalcule_a_la_suppression_dune_ligne(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-31']);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 25,
        ]);
        $this->assertSame('100.00', $order->fresh()->total);

        $itemA->delete();

        $this->assertSame('50.00', $order->fresh()->total);
    }

    public function test_le_total_est_nul_si_une_ligne_na_pas_de_prix_unitaire(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-32']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        // Deuxième ligne sans prix unitaire : le total ne peut plus
        // être considéré comme complet, il repasse à NULL plutôt que
        // d'afficher seulement la somme partielle des lignes connues.
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
        ]);

        $this->assertNull($order->fresh()->total);
    }

    public function test_le_total_est_fige_apres_confirmation_et_receive_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-33']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        $order->markAsOrdered();
        $order->receive([$item->id => 3]);

        // receive() ne modifie que quantity_received sur la ligne : le
        // total figé à la confirmation ne doit pas bouger.
        $this->assertSame('50.00', $order->fresh()->total);
    }

    /*
     * =================================================================
     * Remise (discount_amount)
     * =================================================================
     */

    public function test_remise_a_zero_le_total_est_inchange(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-34']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $this->assertSame('0.00', $order->fresh()->discount_amount);
        $this->assertSame('50.00', $order->fresh()->total);
    }

    public function test_remise_inferieure_au_subtotal(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-35']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        $order->update(['discount_amount' => 20]);

        $this->assertSame('30.00', $order->fresh()->total);
    }

    public function test_remise_egale_au_subtotal(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-36']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $order->update(['discount_amount' => 50]);

        $this->assertSame('0.00', $order->fresh()->total);
    }

    public function test_remise_superieure_au_subtotal_le_total_est_borne_a_zero(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-37']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $order->update(['discount_amount' => 999]);

        $this->assertSame('0.00', $order->fresh()->total);
    }

    public function test_remise_avec_plusieurs_lignes(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-38']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 25,
        ]);
        $this->assertSame('100.00', $order->fresh()->total);

        $order->update(['discount_amount' => 30]);

        $this->assertSame('70.00', $order->fresh()->total);
    }

    public function test_modifier_la_remise_en_brouillon_recalcule_le_total(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-39']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $order->update(['discount_amount' => 10]);
        $this->assertSame('40.00', $order->fresh()->total);

        $order->update(['discount_amount' => 25]);
        $this->assertSame('25.00', $order->fresh()->total);
    }

    public function test_modifier_une_ligne_apres_application_dune_remise_recalcule_correctement_le_total(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-40']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->update(['discount_amount' => 15]);
        $this->assertSame('35.00', $order->fresh()->total);

        // La ligne change (toujours en brouillon) : le total doit
        // refléter le nouveau subtotal moins la même remise.
        $item->update(['quantity_ordered' => 8]);

        $this->assertSame('65.00', $order->fresh()->total);
    }

    public function test_la_remise_est_figee_apres_confirmation_et_receive_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-41']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->update(['discount_amount' => 10]);
        $this->assertSame('40.00', $order->fresh()->total);

        $order->markAsOrdered();
        $order->receive([$item->id => 3]);

        $order->refresh();
        $this->assertSame('10.00', $order->discount_amount);
        $this->assertSame('40.00', $order->total);
    }

    public function test_modifier_la_remise_dune_commande_confirmee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-42']);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);
        $order->update(['discount_amount' => 10]);
    }

    /*
     * =================================================================
     * TVA (tax_rate, gross_tax_amount, tax_amount, total_ttc)
     * =================================================================
     */

    public function test_le_taux_de_tva_se_resout_depuis_le_produit_puis_le_taux_par_defaut_systeme(): void
    {
        $defaultRate = TaxRate::create([
            'label' => 'Taux normal',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_purchase' => true,
        ]);
        $reducedRate = TaxRate::create([
            'label' => 'Taux réduit',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 5,
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-43']);

        // Produit sans taux spécifique -> taux par défaut système.
        $productA = $this->makeProduct();
        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        $this->assertSame($defaultRate->id, $itemA->tax_rate_id);
        $this->assertSame('20.00', $itemA->tax_rate);

        // Produit avec un taux d'achat spécifique -> prime sur le défaut.
        $productB = $this->makeProduct(['purchase_tax_rate_id' => $reducedRate->id]);
        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        $this->assertSame($reducedRate->id, $itemB->tax_rate_id);
        $this->assertSame('5.00', $itemB->tax_rate);
    }

    public function test_sans_remise_le_tax_amount_de_ligne_est_egal_au_gross_tax_amount(): void
    {
        TaxRate::create([
            'label' => 'Taux normal',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_purchase' => true,
        ]);

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-44']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $this->assertSame('10.00', $item->gross_tax_amount);
        $this->assertSame('10.00', $item->tax_amount);
        $this->assertSame('60.00', $order->fresh()->total_ttc);
    }

    /**
     * L'exemple exact validé : 2 lignes à 2 taux différents, remise
     * globale de 20 € répartie au prorata des bases HT.
     */
    public function test_remise_repartie_au_prorata_entre_deux_taux_differents(): void
    {
        $rate20 = TaxRate::create(['label' => 'Taux normal', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);
        $rate10 = TaxRate::create(['label' => 'Taux réduit', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);

        $productA = $this->makeProduct(['purchase_tax_rate_id' => $rate20->id]);
        $productB = $this->makeProduct(['purchase_tax_rate_id' => $rate10->id]);

        $order = PurchaseOrder::create(['reference' => 'BC-TEST-45']);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);

        $order->update(['discount_amount' => 20]);

        $itemA->refresh();
        $itemB->refresh();
        $order->refresh();

        $this->assertSame('20.00', $itemA->gross_tax_amount);
        $this->assertSame('18.00', $itemA->tax_amount);

        $this->assertSame('10.00', $itemB->gross_tax_amount);
        $this->assertSame('9.00', $itemB->tax_amount);

        $this->assertSame('180.00', $order->total);
        $this->assertSame('27.00', $order->tax_amount);
        $this->assertSame('207.00', $order->total_ttc);

        // Réconciliation explicite : la somme des tax_amount de lignes
        // doit toujours égaler le tax_amount de la commande.
        $sumOfLineTaxAmounts = round((float) $itemA->tax_amount + (float) $itemB->tax_amount, 2);
        $this->assertSame($sumOfLineTaxAmounts, (float) $order->tax_amount);
    }

    public function test_ajouter_une_ligne_recalcule_le_tax_amount_des_lignes_existantes(): void
    {
        $rate20 = TaxRate::create(['label' => 'Taux normal', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);

        $product = $this->makeProduct(['purchase_tax_rate_id' => $rate20->id]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-46', 'discount_amount' => 20]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        // Seule : toute la remise lui est allouée.
        $this->assertSame('16.00', $itemA->fresh()->tax_amount);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);

        // À deux, la remise se répartit désormais moitié-moitié.
        $this->assertSame('18.00', $itemA->fresh()->tax_amount);
    }

    public function test_ligne_exoneree_a_un_tax_amount_a_zero_distinct_de_non_resolu(): void
    {
        $exempt = TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable, article 293 B du CGI',
        ]);

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-47']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
            'tax_rate_id' => $exempt->id,
        ]);

        $this->assertNull($item->tax_rate);
        $this->assertSame('0.00', $item->gross_tax_amount);
        $this->assertSame('0.00', $item->tax_amount);
        $this->assertSame('50.00', $order->fresh()->total_ttc);
    }

    public function test_ligne_sans_taux_resolu_a_un_tax_amount_null_mais_total_ht_reste_connu(): void
    {
        // Aucun TaxRate configuré nulle part : ni sur le produit, ni de
        // taux par défaut système.
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-48']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $this->assertNull($item->tax_rate_id);
        $this->assertNull($item->gross_tax_amount);
        $this->assertNull($item->tax_amount);

        $order->refresh();
        // Le HT reste connu (quantité/prix le sont) : seule la partie
        // fiscale, elle, est inconnue.
        $this->assertSame('50.00', $order->total);
        $this->assertNull($order->tax_amount);
        $this->assertNull($order->total_ttc);
    }

    public function test_la_tva_est_figee_apres_confirmation_et_receive_ny_touche_pas(): void
    {
        TaxRate::create([
            'label' => 'Taux normal',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_purchase' => true,
        ]);

        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-49']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('10.00', $item->tax_amount);

        $order->markAsOrdered();
        $order->receive([$item->id => 3]);

        $item->refresh();
        $order->refresh();

        $this->assertSame('10.00', $item->tax_amount);
        $this->assertSame('10.00', $order->tax_amount);
        $this->assertSame('60.00', $order->total_ttc);
    }

    public function test_modifier_le_tax_rate_dune_ligne_confirmee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-50']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);
        $item->update(['tax_amount' => 999]);
    }

    public function test_modifier_le_taux_par_defaut_dun_produit_apres_confirmation_ne_change_pas_lhistorique(): void
    {
        $rateOriginal = TaxRate::create(['label' => 'Taux A', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);
        $rateNouveau = TaxRate::create(['label' => 'Taux B', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 5]);

        $product = $this->makeProduct(['purchase_tax_rate_id' => $rateOriginal->id]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-51']);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        // Le taux par défaut du produit change APRÈS confirmation.
        $product->update(['purchase_tax_rate_id' => $rateNouveau->id]);

        $item->refresh();
        $this->assertSame($rateOriginal->id, $item->tax_rate_id);
        $this->assertSame('20.00', $item->tax_rate);
        $this->assertSame('10.00', $item->tax_amount);
    }

    /**
     * Régression : PurchaseOrder::applyTaxAllocation() réécrit
     * tax_amount par requête directe (whereKey()->update()), donc sur
     * une instance PHP différente de celle retournée par create(). Sans
     * le ->refresh() ajouté dans le hook `saved`, l'objet $item gardait
     * en mémoire gross_tax_amount (valeur AVANT remise) au lieu du
     * tax_amount réellement persisté (APRÈS remise) — ce test échoue
     * si cette régression revient, sans jamais appeler ->fresh().
     */
    public function test_tax_amount_en_memoire_reflete_la_remise_sans_fresh(): void
    {
        $rate = TaxRate::create(['label' => 'Taux normal', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);
        $product = $this->makeProduct(['purchase_tax_rate_id' => $rate->id]);
        $order = PurchaseOrder::create(['reference' => 'BC-TEST-52', 'discount_amount' => 10]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);

        // subtotal=100, remise=10 -> base taxable=90 -> TVA 20%=18.
        $this->assertSame('20.00', $item->gross_tax_amount);
        $this->assertSame('18.00', $item->tax_amount);
        $this->assertNotSame($item->gross_tax_amount, $item->tax_amount);
    }
}
