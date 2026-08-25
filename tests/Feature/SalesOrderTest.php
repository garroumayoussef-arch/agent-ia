<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Étape T11b : StockMovement::creating() (déclenché par
     * SalesOrder::ship()) résout désormais systématiquement un
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

    /*
     * =================================================================
     * Sous-total (subtotal)
     * =================================================================
     */

    public function test_le_sous_total_dune_ligne_est_calcule_automatiquement_a_la_creation(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-14']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 12.50,
        ]);

        $this->assertSame('62.50', $item->subtotal);
    }

    public function test_le_sous_total_est_recalcule_quand_la_quantite_ou_le_prix_change_en_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-15']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-16']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $this->assertNull($item->unit_price);
        $this->assertNull($item->subtotal);
    }

    public function test_le_sous_total_est_fige_apres_confirmation_et_ship_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-17']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $item->subtotal);

        $order->markAsConfirmed();
        $order->ship([$item->id => 3]);

        $item->refresh();

        // ship() ne modifie que quantity_shipped : le sous-total figé à
        // la confirmation ne doit pas bouger.
        $this->assertSame(3, $item->quantity_shipped);
        $this->assertSame('50.00', $item->subtotal);
    }

    public function test_modifier_le_prix_unitaire_dune_ligne_est_rejete_hors_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-18']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);
        $item->update(['unit_price' => 20]);
    }

    public function test_supprimer_une_ligne_ne_touche_pas_le_sous_total_des_autres_lignes(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-19']);

        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 10,
        ]);
        $itemB = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
            'unit_price' => 20,
        ]);

        $itemA->delete();

        $this->assertSame(1, SalesOrderItem::count());
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-20']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $this->assertSame('50.00', $order->fresh()->total);
    }

    public function test_le_total_de_la_commande_est_recalcule_a_lajout_dune_deuxieme_ligne(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-21']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 25,
        ]);

        $this->assertSame('100.00', $order->fresh()->total);
    }

    public function test_le_total_de_la_commande_est_recalcule_quand_une_ligne_est_modifiee_en_brouillon(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-22']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-23']);

        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-24']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        // Deuxième ligne sans prix unitaire : le total ne peut plus
        // être considéré comme complet, il repasse à NULL plutôt que
        // d'afficher seulement la somme partielle des lignes connues.
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 3,
        ]);

        $this->assertNull($order->fresh()->total);
    }

    public function test_le_total_est_fige_apres_confirmation_et_ship_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-25']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('50.00', $order->fresh()->total);

        $order->markAsConfirmed();
        $order->ship([$item->id => 3]);

        // ship() ne modifie que quantity_shipped sur la ligne : le
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-26']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-27']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-28']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-29']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-30']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-31']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-32']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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

    public function test_la_remise_est_figee_apres_confirmation_et_ship_ny_touche_pas(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-33']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->update(['discount_amount' => 10]);
        $this->assertSame('40.00', $order->fresh()->total);

        $order->markAsConfirmed();
        $order->ship([$item->id => 3]);

        $order->refresh();
        $this->assertSame('10.00', $order->discount_amount);
        $this->assertSame('40.00', $order->total);
    }

    public function test_modifier_la_remise_dune_commande_confirmee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-34']);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsConfirmed();

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
            'is_default_sale' => true,
        ]);
        $reducedRate = TaxRate::create([
            'label' => 'Taux réduit',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 5,
        ]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-35']);

        // Produit sans taux spécifique -> taux par défaut système.
        $productA = $this->makeProduct();
        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        $this->assertSame($defaultRate->id, $itemA->tax_rate_id);
        $this->assertSame('20.00', $itemA->tax_rate);

        // Produit avec un taux de vente spécifique -> prime sur le défaut.
        $productB = $this->makeProduct(['sale_tax_rate_id' => $reducedRate->id]);
        $itemB = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
            'is_default_sale' => true,
        ]);

        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-36']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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

        $productA = $this->makeProduct(['sale_tax_rate_id' => $rate20->id]);
        $productB = $this->makeProduct(['sale_tax_rate_id' => $rate10->id]);

        $order = SalesOrder::create(['reference' => 'CMD-TEST-37']);

        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        $itemB = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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

        $product = $this->makeProduct(['sale_tax_rate_id' => $rate20->id]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-38', 'discount_amount' => 20]);

        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);
        // Seule : toute la remise lui est allouée.
        $this->assertSame('16.00', $itemA->fresh()->tax_amount);

        SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-39']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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
        $order = SalesOrder::create(['reference' => 'CMD-TEST-40']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
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

    public function test_la_tva_est_figee_apres_confirmation_et_ship_ny_touche_pas(): void
    {
        TaxRate::create([
            'label' => 'Taux normal',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_sale' => true,
        ]);

        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-41']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $this->assertSame('10.00', $item->tax_amount);

        $order->markAsConfirmed();
        $order->ship([$item->id => 3]);

        $item->refresh();
        $order->refresh();

        $this->assertSame('10.00', $item->tax_amount);
        $this->assertSame('10.00', $order->tax_amount);
        $this->assertSame('60.00', $order->total_ttc);
    }

    public function test_modifier_le_tax_rate_dune_ligne_confirmee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-TEST-42']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);
        $item->update(['tax_amount' => 999]);
    }

    public function test_modifier_le_taux_par_defaut_dun_produit_apres_confirmation_ne_change_pas_lhistorique(): void
    {
        $rateOriginal = TaxRate::create(['label' => 'Taux A', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);
        $rateNouveau = TaxRate::create(['label' => 'Taux B', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 5]);

        $product = $this->makeProduct(['sale_tax_rate_id' => $rateOriginal->id]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-43']);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsConfirmed();

        // Le taux par défaut du produit change APRÈS confirmation.
        $product->update(['sale_tax_rate_id' => $rateNouveau->id]);

        $item->refresh();
        $this->assertSame($rateOriginal->id, $item->tax_rate_id);
        $this->assertSame('20.00', $item->tax_rate);
        $this->assertSame('10.00', $item->tax_amount);
    }

    /**
     * Régression : SalesOrder::applyTaxAllocation() réécrit tax_amount
     * par requête directe (whereKey()->update()), donc sur une instance
     * PHP différente de celle retournée par create(). Sans le
     * ->refresh() ajouté dans le hook `saved`, l'objet $item gardait en
     * mémoire gross_tax_amount (valeur AVANT remise) au lieu du
     * tax_amount réellement persisté (APRÈS remise) — ce test échoue si
     * cette régression revient, sans jamais appeler ->fresh().
     */
    public function test_tax_amount_en_memoire_reflete_la_remise_sans_fresh(): void
    {
        $rate = TaxRate::create(['label' => 'Taux normal', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20]);
        $product = $this->makeProduct(['sale_tax_rate_id' => $rate->id]);
        $order = SalesOrder::create(['reference' => 'CMD-TEST-44', 'discount_amount' => 10]);

        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 100,
        ]);

        // subtotal=100, remise=10 -> base taxable=90 -> TVA 20%=18.
        $this->assertSame('20.00', $item->gross_tax_amount);
        $this->assertSame('18.00', $item->tax_amount);
        $this->assertNotSame($item->gross_tax_amount, $item->tax_amount);
    }

    /*
     * =================================================================
     * Étape T13 — sélection de l'entrepôt d'expédition
     * =================================================================
     */

    public function test_expedier_avec_un_entrepot_explicite_enregistre_le_bon_entrepot(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t13']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);
        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-1']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 3], $warehouse->id);

        $movement = StockMovement::first();
        $this->assertSame($warehouse->id, $movement->warehouse_id);
    }

    public function test_expedier_sans_entrepot_avec_un_seul_entrepot_actif_retombe_dessus(): void
    {
        $default = Warehouse::where('is_default', true)->first();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-2']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 3]);

        $movement = StockMovement::first();
        $this->assertSame($default->id, $movement->warehouse_id);
    }

    public function test_expedier_est_refuse_si_plusieurs_entrepots_actifs_sans_selection_explicite(): void
    {
        Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t13']); // 2e entrepôt actif, en plus du défaut de setUp()
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-3']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            $order->ship([$item->id => 3]);
        } finally {
            $this->assertSame(0, StockMovement::count());
            $item->refresh();
            $this->assertSame(0, $item->quantity_shipped);
        }
    }

    public function test_expedier_avec_un_entrepot_inexistant_est_refuse(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-4']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            $order->ship([$item->id => 3], 999999);
        } finally {
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_expedier_avec_un_entrepot_inactif_est_refuse(): void
    {
        $inactive = Warehouse::create(['name' => 'Entrepôt inactif', 'code' => 'inactif-t13', 'is_active' => false]);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-5']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 3,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            $order->ship([$item->id => 3], $inactive->id);
        } finally {
            $this->assertSame(0, StockMovement::count());
        }
    }

    /**
     * Le test le plus important de T13 : la vérification de stock doit
     * porter sur l'entrepôt SÉLECTIONNÉ, jamais uniquement sur le stock
     * global Product/ProductVariant (même principe déjà prouvé pour les
     * transferts en T12, ici appliqué à ship()).
     */
    public function test_expedition_refusee_si_stock_insuffisant_dans_lentrepot_selectionne_meme_si_stock_global_suffisant(): void
    {
        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t13-insuf']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t13-insuf']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 13]); // stock global largement suffisant

        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 3]); // entrepôt A insuffisant
        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 10]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-6']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            // 5 > 3 (entrepôt A) mais 5 < 13 (stock global) : doit être
            // refusé malgré la suffisance globale.
            $order->ship([$item->id => 5], $warehouseA->id);
        } finally {
            $this->assertSame(0, StockMovement::count());
            $item->refresh();
            $this->assertSame(0, $item->quantity_shipped);
            $this->assertSame(13, $variant->fresh()->stock);
            $this->assertSame(3, \App\Models\WarehouseStock::where('warehouse_id', $warehouseA->id)->value('stock'));
            $this->assertSame(10, \App\Models\WarehouseStock::where('warehouse_id', $warehouseB->id)->value('stock'));
        }
    }

    public function test_expedier_met_a_jour_warehouse_stocks_pour_lentrepot_selectionne(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t13-ws']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);
        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 10]);

        $order = SalesOrder::create(['reference' => 'CMD-T13-7']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 4,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 4], $warehouse->id);

        $this->assertSame(
            6,
            \App\Models\WarehouseStock::where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->value('stock')
        );
        $this->assertSame(6, $variant->fresh()->stock);
    }
}
