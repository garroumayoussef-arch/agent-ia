<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
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
 * Chantier Dropshipping, étape D2.7.1 — test de caractérisation de la
 * future visibilité READ-ONLY de la traçabilité sourcing / commande
 * fournisseur sur l'infolist de SalesOrder (D2.7.2), ÉCRIT AVANT TOUT
 * CODE — même discipline que SalesOrderSourcingActionTest (D2.5.1) et
 * SalesOrderCreatePurchaseOrdersActionTest (D2.6.1).
 *
 * Intégralement ROUGE tant que SalesOrderInfolist.php n'a pas été
 * modifié : aucune des informations testées ci-dessous n'est
 * actuellement affichée. Portée strictement lecture seule : aucune
 * action, aucune mutation, uniquement de l'affichage. Ce test ne crée
 * ni ne modifie aucune règle métier — il consomme exclusivement
 * SalesOrderItemAllocation::recordFor() (D2.4.7) et
 * CreatePurchaseOrdersFromAllocations::execute() (D2.6.3), tous deux
 * inchangés.
 *
 * Libellés/format validés explicitement par l'opérateur humain avant
 * l'écriture de ce fichier : "Non sourcé" (aucune allocation), "Non
 * généré" (allocation sans PurchaseOrder), "{fournisseur} — {référence}"
 * (allocation convertie en PurchaseOrder).
 *
 * Chantier Dropshipping, étape D2.8 (Gap B) — méthodes 4 et 5 ajoutées :
 * caractérisation ADDITIVE d'une nouvelle colonne "Statut achat
 * fournisseur" (Option B2, validée explicitement), STRICTEMENT
 * SÉPARÉE de l'entrée "sourcing_status" ci-dessus dont le contrat D2.7
 * (déjà validé, tagué, poussé) reste inchangé — aucune des 3 méthodes
 * précédentes n'est modifiée.
 */
class SalesOrderInfolistSourcingTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    private function createSourcing(Product $product, string $supplierName): void
    {
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $supplierName])->id,
            'is_active' => true,
        ]);
    }

    /*
     * =================================================================
     * 1. Ligne JAMAIS sourcée (aucune allocation) : affichage propre,
     *    aucune erreur, aucune fuite de "null".
     * =================================================================
     */
    public function test_ligne_sans_allocation_affiche_un_etat_neutre(): void
    {
        $product = Product::factory()->create();
        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Non sourcé');
    }

    /*
     * =================================================================
     * 2. Ligne allouée (SalesOrderItemAllocation existe) mais AUCUN
     *    PurchaseOrder généré : l'achat fournisseur est explicitement
     *    "à générer".
     * =================================================================
     */
    public function test_ligne_allouee_sans_commande_fournisseur_affiche_labsence_de_commande(): void
    {
        $product = Product::factory()->create();
        $this->createSourcing($product, 'Fournisseur Alpha');

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();
        SalesOrderItemAllocation::recordFor($item->fresh());

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful()
            ->assertSee('Non généré');
    }

    /*
     * =================================================================
     * 3. Ligne allouée ET convertie en PurchaseOrder (D2.6) : le
     *    fournisseur et la référence de la commande fournisseur sont
     *    visibles, combinés, depuis la SalesOrder d'origine — format
     *    "{fournisseur} — {référence}" validé explicitement.
     * =================================================================
     */
    public function test_ligne_convertie_en_commande_fournisseur_affiche_fournisseur_et_reference(): void
    {
        $product = Product::factory()->create();
        $this->createSourcing($product, 'Fournisseur Beta');

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();
        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::whereKey($allocation->id)->get()
        );

        $purchaseOrder = PurchaseOrder::firstOrFail();

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful()
            ->assertSee("Fournisseur Beta — {$purchaseOrder->reference}");
    }

    /*
     * =================================================================
     * 4. (D2.8, Gap B) PurchaseOrder généré, statut par défaut (Brouillon)
     *    : la nouvelle colonne "Statut achat fournisseur" affiche le
     *    libellé réel du statut, réutilisant PurchaseOrdersTable::
     *    statusLabel() (aucun nouveau statut métier).
     * =================================================================
     */
    public function test_statut_achat_fournisseur_affiche_le_libelle_du_statut(): void
    {
        $product = Product::factory()->create();
        $this->createSourcing($product, 'Fournisseur Delta');

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();
        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::whereKey($allocation->id)->get()
        );

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful()
            ->assertSee('Brouillon');
    }

    /*
     * =================================================================
     * 5. (D2.8, Gap B) PurchaseOrder ANNULÉ : la nouvelle colonne
     *    distingue explicitement ce cas — objectif central du Gap B,
     *    absent du contrat D2.7 (qui affiche la même chose quel que
     *    soit le statut du PurchaseOrder).
     * =================================================================
     */
    public function test_statut_achat_fournisseur_distingue_un_achat_annule(): void
    {
        $product = Product::factory()->create();
        $this->createSourcing($product, 'Fournisseur Epsilon');

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();
        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::whereKey($allocation->id)->get()
        );

        PurchaseOrder::firstOrFail()->cancel();

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful()
            ->assertSee('Annulé');
    }
}
