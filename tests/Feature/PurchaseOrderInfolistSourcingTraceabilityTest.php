<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.8 (Gap A) — test de caractérisation de
 * la traçabilité symétrique READ-ONLY : origine SalesOrder visible sur
 * le PurchaseOrder qui l'a générée (App\Services\
 * CreatePurchaseOrdersFromAllocations, D2.6.3), miroir exact de la
 * visibilité déjà livrée en D2.7 dans l'autre sens. ÉCRIT AVANT TOUT
 * CODE (PurchaseOrderInfolist.php n'affiche aujourd'hui aucune de ces
 * informations) — même discipline que
 * SalesOrderInfolistSourcingTraceabilityTest (D2.7.1).
 *
 * Portée strictement lecture seule : aucune action, aucune mutation.
 * Libellés validés explicitement par l'opérateur humain avant l'écriture
 * de ce fichier : "Achat direct" (aucune SalesOrder d'origine), "Vente
 * {référence}" (PurchaseOrder généré depuis une SalesOrder).
 */
class PurchaseOrderInfolistSourcingTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    /*
     * =================================================================
     * 1. Ligne d'achat manuelle (hors Dropshipping) : aucune allocation
     *    ne pointe vers elle — flux d'achat classique, préexistant.
     * =================================================================
     */
    public function test_ligne_dachat_manuelle_affiche_achat_direct(): void
    {
        $order = PurchaseOrder::create(['reference' => 'BC-MANUEL-'.uniqid()]);
        $order->items()->create([
            'product_id' => Product::factory()->create()->id,
            'quantity_ordered' => 3,
        ]);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Achat direct');
    }

    /*
     * =================================================================
     * 2. Ligne générée depuis une SalesOrder via le Dropshipping (D2.6) :
     *    la référence de la SalesOrder d'origine est visible depuis le
     *    PurchaseOrder.
     * =================================================================
     */
    public function test_ligne_generee_par_dropshipping_affiche_la_vente_dorigine(): void
    {
        $product = Product::factory()->create();
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => 'Fournisseur Gamma'])->id,
            'is_active' => true,
        ]);

        $salesOrder = SalesOrder::factory()->create(['reference' => 'CMD-D28-'.uniqid()]);
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
        ]);
        $salesOrder->markAsConfirmed();
        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::whereKey($allocation->id)->get()
        );

        $purchaseOrder = PurchaseOrder::firstOrFail();

        Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrder->getKey()])
            ->assertSuccessful()
            ->assertSee("Vente {$salesOrder->reference}");
    }
}
