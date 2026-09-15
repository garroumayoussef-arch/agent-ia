<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.5.1 — test de caractérisation de la
 * future action Filament "allocateSourcing" (D2.5.2 : Trait
 * HasSalesOrderSourcingAction, D2.5.3 : intégration dans
 * EditSalesOrder/ViewSalesOrder), ÉCRIT AVANT TOUT CODE D2.5.2/D2.5.3 —
 * même discipline que D2.2/D2.3.1/D2.4.1.
 *
 * L'action "allocateSourcing" n'existe sur AUCUNE page à ce stade : ce
 * fichier est donc, par construction, intégralement ROUGE tant que
 * D2.5.2 (création du Trait) et D2.5.3 (branchement dans
 * EditSalesOrder/ViewSalesOrder) n'ont pas été réalisés. Il doit devenir
 * vert à l'identique une fois ces deux étapes terminées, sans qu'aucune
 * assertion ci-dessous n'ait besoin d'être modifiée.
 *
 * Portée volontairement limitée aux effets OBSERVABLES en base
 * (allocations créées/absentes, visibilité/autorisation de l'action) :
 * le contenu exact de la Notification de synthèse n'est pas figé ici
 * (détail d'implémentation non encore arbitré), et l'absence de logique
 * de sélection de fournisseur dans le futur Trait relève d'une revue de
 * code, pas d'une assertion runtime.
 *
 * Couverture des 14 scénarios validés (sections 11-14 du plan D2.5) :
 * délégation exclusive à SalesOrderItemAllocation::recordFor() (D2.4.7,
 * inchangé) et SupplierSourcingResolver::best() (D2.4.6, inchangé,
 * consulté uniquement à travers recordFor()) ; aucune référence à
 * `activity` ; aucun effet de bord sur StockMovement/PurchaseOrder.
 */
class SalesOrderSourcingActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
    }

    private function actingAsManager(): User
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);

        return $manager;
    }

    private function createSourcing(Product $product, array $overrides = []): void
    {
        $product->supplierSourcings()->create(array_merge([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ], $overrides));
    }

    private function createConfirmedOrderWithItem(?Product $product = null): array
    {
        $product ??= Product::factory()->create();
        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();

        return [$order->fresh(), $item->fresh(), $product];
    }

    /*
     * =================================================================
     * 1. CONFIRMED, sourcing actif sur toutes les lignes
     * =================================================================
     */
    public function test_confirmee_avec_sourcing_actif_alloue_toutes_les_lignes_eligibles(): void
    {
        $this->actingAsManager();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $this->createSourcing($productA);
        $this->createSourcing($productB);

        $order = SalesOrder::factory()->create();
        $itemA = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productA->id]);
        $itemB = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productB->id]);
        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertNotNull($itemA->fresh()->allocation);
        $this->assertNotNull($itemB->fresh()->allocation);
        $this->assertSame(2, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 2. Ligne sans sourcing actif parmi d'autres
     * =================================================================
     */
    public function test_ligne_sans_sourcing_actif_reste_non_allouee_sans_bloquer_les_autres(): void
    {
        $this->actingAsManager();

        $productAvecSourcing = Product::factory()->create();
        $productSansSourcing = Product::factory()->create();
        $this->createSourcing($productAvecSourcing);

        $order = SalesOrder::factory()->create();
        $itemAlloue = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productAvecSourcing->id]);
        $itemEnEchec = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productSansSourcing->id]);
        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertNotNull($itemAlloue->fresh()->allocation);
        $this->assertNull($itemEnEchec->fresh()->allocation);
    }

    /*
     * =================================================================
     * 3. Rejouer sur une ligne déjà allouée
     * =================================================================
     */
    public function test_rejouer_sur_une_ligne_deja_allouee_ne_cree_pas_de_doublon(): void
    {
        $this->actingAsManager();

        [$order, $item] = $this->createConfirmedOrderWithItem();
        $this->createSourcing($item->product);
        SalesOrderItemAllocation::recordFor($item);

        $this->assertSame(1, SalesOrderItemAllocation::count());

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertSame(1, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 4-6. Statuts pour lesquels l'action doit rester invisible
     * =================================================================
     */
    public function test_commande_draft_action_invisible(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('allocateSourcing');
    }

    public function test_commande_cancelled_action_invisible(): void
    {
        $this->actingAsManager();

        [$order] = $this->createConfirmedOrderWithItem();
        $order->cancel();

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('allocateSourcing');
    }

    public function test_commande_shipped_action_invisible(): void
    {
        $manager = $this->actingAsManager();
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        [$order, $item] = $this->createConfirmedOrderWithItem(Product::factory()->create(['stock' => 100]));
        $order->ship([$item->id => $item->quantity_ordered]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('allocateSourcing');
    }

    /*
     * =================================================================
     * 7. PARTIALLY_SHIPPED — seules les lignes restantes (non allouées)
     * sont concernées, indépendamment de leur quantité déjà expédiée
     * =================================================================
     */
    public function test_partially_shipped_seules_les_lignes_restantes_sont_allouees(): void
    {
        $manager = $this->actingAsManager();
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        $productExpedie = Product::factory()->create(['stock' => 100]);
        $productRestant = Product::factory()->create();
        $this->createSourcing($productExpedie);
        $this->createSourcing($productRestant);

        $order = SalesOrder::factory()->create();
        $itemExpedie = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productExpedie->id, 'quantity_ordered' => 2]);
        $itemRestant = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productRestant->id, 'quantity_ordered' => 2]);
        $order->markAsConfirmed();
        SalesOrderItemAllocation::recordFor($itemExpedie->fresh());
        $order->fresh()->ship([$itemExpedie->id => 2]);

        $this->assertSame(SalesOrder::STATUS_PARTIALLY_SHIPPED, $order->fresh()->status);
        $this->assertNull($itemRestant->fresh()->allocation);

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->callAction('allocateSourcing');

        $this->assertNotNull($itemRestant->fresh()->allocation);
        $this->assertSame(2, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 8. Sourcing spécifique actif sur la variante : priorité stricte,
     * jamais de mélange avec le générique du produit parent (délégation
     * au comportement déjà testé du Resolver, D2.4.6/D2.4.7)
     * =================================================================
     */
    public function test_ligne_avec_sourcing_specifique_actif_est_alloue_au_sourcing_specifique(): void
    {
        $this->actingAsManager();

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);
        $sourcingSpecifique = $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertSame($sourcingSpecifique->id, $item->fresh()->allocation->supplier_product_sourcing_id);
    }

    /*
     * =================================================================
     * 9. Ligne sans variante — allocation au niveau produit
     * =================================================================
     */
    public function test_ligne_sans_variante_est_allouee_au_niveau_produit(): void
    {
        $this->actingAsManager();

        [$order, $item, $product] = $this->createConfirmedOrderWithItem();
        $this->createSourcing($product);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $allocation = $item->fresh()->allocation;
        $this->assertNotNull($allocation);
        $this->assertNull($allocation->supplierProductSourcing->product_variant_id);
    }

    /*
     * =================================================================
     * 10. Utilisateur non autorisé — visibilité dépendant UNIQUEMENT du
     * statut (décision D2.5.3, Option A) : le bouton reste visible pour
     * un viewer, exactement comme confirmOrderAction/shipOrderAction/
     * cancelOrderAction (HasSalesOrderWorkflowActions) — seule
     * ->authorize() bloque réellement l'exécution, jamais ->visible().
     * Blocage réel vérifié sur appel direct forgé (même convention que
     * SalesOrderInvoiceActionTest/SalesOrderWorkflowAuthorizationTest,
     * étape T25-B/T25-C).
     * =================================================================
     */
    public function test_viewer_voit_laction_mais_ne_peut_pas_lexecuter(): void
    {
        $this->actingAsManager();
        [$order] = $this->createConfirmedOrderWithItem();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionVisible('allocateSourcing');
    }

    public function test_viewer_ne_peut_pas_declencher_laction_par_appel_direct(): void
    {
        $this->actingAsManager();
        [$order, $item, $product] = $this->createConfirmedOrderWithItem();
        $this->createSourcing($product);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->call('mountAction', 'allocateSourcing');

        $this->assertNull($item->fresh()->allocation);
    }

    /*
     * =================================================================
     * 11. Transversalité — comportement identique pour plusieurs
     * activités utilisant Product, aucune branche liée à `activity`
     * =================================================================
     */
    public function test_le_comportement_est_identique_pour_plusieurs_activites_utilisant_product(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();
        $items = [];

        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);
            $this->createSourcing($product);
            $items[] = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        }

        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->callAction('allocateSourcing');

        foreach ($items as $item) {
            $this->assertNotNull($item->fresh()->allocation);
        }
    }

    /*
     * =================================================================
     * 12. Absence d'effet de bord — aucun StockMovement/PurchaseOrder,
     * statut de la commande inchangé par l'action elle-même
     * =================================================================
     */
    public function test_aucun_effet_de_bord_sur_stock_ou_commandes(): void
    {
        $this->actingAsManager();

        [$order, $item, $product] = $this->createConfirmedOrderWithItem();
        $this->createSourcing($product);
        $statutAvant = $order->status;

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertSame($statutAvant, $order->fresh()->status);
    }

    /*
     * =================================================================
     * 13. Commande sans ligne (état inatteignable via markAsConfirmed(),
     * construit ici directement pour vérifier l'absence de crash
     * défensif) — voir limitation documentée en tête de fichier
     * =================================================================
     */
    public function test_commande_sans_ligne_ne_provoque_aucun_crash(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create(['status' => SalesOrder::STATUS_CONFIRMED]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertSame(0, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 14. Rejouer l'action sur une commande entièrement allouée
     * =================================================================
     */
    public function test_rejouer_laction_sur_une_commande_entierement_allouee_est_un_no_op(): void
    {
        $this->actingAsManager();

        [$order, $item, $product] = $this->createConfirmedOrderWithItem();
        $this->createSourcing($product);
        SalesOrderItemAllocation::recordFor($item);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');
        $countApresPremierAppel = SalesOrderItemAllocation::count();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('allocateSourcing');

        $this->assertSame($countApresPremierAppel, SalesOrderItemAllocation::count());
    }
}
