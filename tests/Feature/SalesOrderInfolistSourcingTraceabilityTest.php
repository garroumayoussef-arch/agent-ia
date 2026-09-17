<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItemReturn;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
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

        $warehouse = Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $manager = User::factory()->create()->assignRole('manager');
        // Chantier Dropshipping, étape D2.10 — nécessaire aux scénarios
        // de ré-allocation (PurchaseOrder::receive() /
        // PurchaseOrderItemReturn::recordFor()), sans impact sur les
        // scénarios D2.7/D2.8 existants (aucun d'eux ne touche au stock).
        $manager->warehouses()->attach($warehouse->id);
        $this->actingAs($manager);
    }

    private function createSourcing(Product $product, string $supplierName): void
    {
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $supplierName])->id,
            'is_active' => true,
        ]);
    }

    /**
     * Chantier Dropshipping, étape D2.10 — construit une ligne
     * intégralement ré-allouée (D2.9) : allocation initiale vers
     * $ancienFournisseur, commande fournisseur générée/reçue/retournée
     * intégralement, puis reallocateFor() vers $nouveauFournisseur
     * (seul fournisseur alternatif actif restant, jamais le fournisseur
     * exclu). Retourne [SalesOrder, SalesOrderItem].
     *
     * @return array{0: SalesOrder, 1: SalesOrderItem}
     */
    private function createReallocatedLine(
        string $ancienFournisseur = 'Fournisseur Zeta',
        string $nouveauFournisseur = 'Fournisseur Eta',
        int $quantity = 5,
    ): array {
        $product = Product::factory()->create();
        $this->createSourcing($product, $ancienFournisseur);
        $this->createSourcing($product, $nouveauFournisseur);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
        ]);
        $order->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $purchaseOrder->markAsOrdered();
        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => $quantity]);
        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), $quantity, now()->toDateString());

        SalesOrderItemAllocation::reallocateFor($allocation->fresh());

        return [$order->fresh(), $item->fresh()];
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

    /*
     * =================================================================
     * D2.10 — traçabilité READ-ONLY de la ré-allocation (D2.9)
     * =================================================================
     */

    /**
     * 6. Ligne jamais ré-allouée (allocation racine simple) : aucune
     *    information de ré-allocation n'apparaît — non-régression sur
     *    la totalité des lignes normales existantes (D2.4.7 à D2.8).
     */
    public function test_ligne_jamais_reallouee_naffiche_aucune_information_de_reallocation(): void
    {
        $product = Product::factory()->create();
        $this->createSourcing($product, 'Fournisseur Thêta');

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();
        SalesOrderItemAllocation::recordFor($item->fresh());

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Ré-alloué depuis');
    }

    /**
     * 7. Ligne ré-allouée (D2.9, retour intégral puis reallocateFor()) :
     *    le fournisseur PRÉCÉDENT (celui remplacé) est visible depuis la
     *    SalesOrder d'origine, via SalesOrderItemAllocation::
     *    replacesAllocation() (D2.9, source de vérité unique).
     */
    public function test_ligne_reallouee_affiche_le_fournisseur_precedent(): void
    {
        [$order] = $this->createReallocatedLine('Fournisseur Zeta', 'Fournisseur Eta');

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Ré-alloué depuis Fournisseur Zeta');
    }

    /**
     * 8. Non-régression croisée : après ré-allocation, sourcing_status
     *    (contrat D2.7, strictement inchangé) continue d'afficher le
     *    NOUVEAU fournisseur une fois sa propre commande fournisseur
     *    générée (D2.6) — la nouvelle entrée D2.10 coexiste avec le
     *    contrat D2.7 sans le modifier ni le dupliquer. Génère
     *    explicitement le PurchaseOrder de la nouvelle allocation :
     *    sans lui, sourcing_status afficherait "Non généré" quel que
     *    soit le fournisseur (contrat D2.7 inchangé, revérifié ici).
     */
    public function test_ligne_reallouee_affiche_toujours_le_sourcing_status_du_nouveau_fournisseur(): void
    {
        [$order, $item] = $this->createReallocatedLine('Fournisseur Zeta', 'Fournisseur Eta');

        (new CreatePurchaseOrdersFromAllocations)->execute(
            new Collection([$item->fresh()->allocation])
        );

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Fournisseur Eta')
            ->assertDontSee('Ré-alloué depuis Fournisseur Eta');
    }

    /**
     * 9. Deux lignes sur la même commande, une seule ré-allouée :
     *    l'information de ré-allocation n'apparaît que sur la ligne
     *    concernée — garde contre une fuite d'état entre lignes d'un
     *    même RepeatableEntry.
     */
    public function test_reallocation_sur_une_ligne_naffecte_pas_lautre_ligne_de_la_meme_commande(): void
    {
        [$order, $itemReallouee] = $this->createReallocatedLine('Fournisseur Zeta', 'Fournisseur Eta');

        // Ligne allouée mais jamais convertie/reçue/ré-allouée : sert
        // uniquement à prouver l'absence de fuite d'état, sourcing_status
        // affichera "Non généré" (contrat D2.7 inchangé), sans rapport
        // avec l'assertion de ce test.
        $autreProduit = Product::factory()->create();
        $this->createSourcing($autreProduit, 'Fournisseur Iota');
        $autreItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $autreProduit->id,
        ]);
        SalesOrderItemAllocation::recordFor($autreItem->fresh());

        $response = Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertSuccessful();

        $response->assertSee('Ré-alloué depuis Fournisseur Zeta');

        $html = $response->html();
        $this->assertSame(1, substr_count($html, 'Ré-alloué depuis'));
    }
}
