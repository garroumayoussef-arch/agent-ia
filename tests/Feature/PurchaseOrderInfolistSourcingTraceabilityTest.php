<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
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
use Illuminate\Support\Facades\DB;
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

        $warehouse = Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $manager = User::factory()->create()->assignRole('manager');
        // Chantier Dropshipping, étape D2.11 — nécessaire aux scénarios
        // de ré-allocation (PurchaseOrder::receive() /
        // PurchaseOrderItemReturn::recordFor()), sans impact sur les
        // scénarios Gap A existants (aucun ne touche au stock).
        $manager->warehouses()->attach($warehouse->id);
        $this->actingAs($manager);
    }

    /**
     * Chantier Dropshipping, étape D2.11 — construit une ligne
     * intégralement ré-allouée (D2.9) et sa nouvelle commande fournisseur
     * (D2.6). Retourne [ancienPurchaseOrder, nouveauPurchaseOrder].
     *
     * @return array{0: PurchaseOrder, 1: PurchaseOrder}
     */
    private function createReallocatedPurchaseOrders(
        string $ancienFournisseur = 'Fournisseur Zeta',
        string $nouveauFournisseur = 'Fournisseur Eta',
        int $quantity = 5,
    ): array {
        $product = Product::factory()->create();
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $ancienFournisseur])->id,
            'is_active' => true,
        ]);
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $nouveauFournisseur])->id,
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
        ]);
        $order->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $ancienPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $ancienPurchaseOrder->markAsOrdered();
        $ancienPurchaseOrderItem = $ancienPurchaseOrder->items()->first();
        $ancienPurchaseOrder->fresh()->receive([$ancienPurchaseOrderItem->id => $quantity]);
        PurchaseOrderItemReturn::recordFor($ancienPurchaseOrderItem->fresh(), $quantity, now()->toDateString());

        $nouvelleAllocation = SalesOrderItemAllocation::reallocateFor($allocation->fresh());

        $nouveauPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$nouvelleAllocation]))['created'][0];

        return [$ancienPurchaseOrder->fresh(), $nouveauPurchaseOrder->fresh()];
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
            ->assertSee('Achat direct')
            // Chantier Dropshipping, étape D2.11 — non-régression :
            // aucune information "Achat remplacé" sur un achat manuel.
            ->assertDontSee('Achat remplacé');
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
            ->assertSee("Vente {$salesOrder->reference}")
            // Chantier Dropshipping, étape D2.11 — non-régression :
            // allocation racine (jamais ré-allouée), aucune information
            // "Achat remplacé".
            ->assertDontSee('Achat remplacé');
    }

    /*
     * =================================================================
     * D2.11 — traçabilité READ-ONLY symétrique de la ré-allocation
     * (D2.9/D2.10), côté PurchaseOrderInfolist
     * =================================================================
     */

    /**
     * 3. PurchaseOrder généré après une ré-allocation (D2.9) : la
     *    référence de l'ANCIEN PurchaseOrder remplacé est visible,
     *    uniquement la référence (ni fournisseur, ni SalesOrder,
     *    décisions validées).
     */
    public function test_achat_issu_dune_reallocation_affiche_la_reference_de_lachat_remplace(): void
    {
        [$ancienPurchaseOrder, $nouveauPurchaseOrder] = $this->createReallocatedPurchaseOrders();

        Livewire::test(ViewPurchaseOrder::class, ['record' => $nouveauPurchaseOrder->getKey()])
            ->assertSuccessful()
            ->assertSee('Achat remplacé')
            ->assertSee($ancienPurchaseOrder->reference);
    }

    /**
     * 4. Le fournisseur remplacé et la référence de la SalesOrder
     *    d'origine ne doivent JAMAIS apparaître dans le libellé "Achat
     *    remplacé" lui-même (décisions validées : référence du
     *    PurchaseOrder uniquement).
     */
    public function test_achat_remplace_naffiche_ni_le_fournisseur_ni_la_salesorder_dorigine(): void
    {
        [, $nouveauPurchaseOrder] = $this->createReallocatedPurchaseOrders('Fournisseur Zeta', 'Fournisseur Eta');

        Livewire::test(ViewPurchaseOrder::class, ['record' => $nouveauPurchaseOrder->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Achat remplacé Fournisseur Zeta')
            ->assertDontSee('Achat remplacé Vente');
    }

    /**
     * 5. Ancien PurchaseOrder (celui remplacé) : sa propre vue ne doit
     *    jamais afficher "Achat remplacé" pour sa propre ligne — cette
     *    information n'existe que du côté du NOUVEAU PurchaseOrder,
     *    jamais rétroactivement sur l'ancien (immutabilité D2.9,
     *    aucune écriture sur l'historique).
     */
    public function test_ancien_achat_naffiche_jamais_achat_remplace_pour_lui_meme(): void
    {
        [$ancienPurchaseOrder] = $this->createReallocatedPurchaseOrders();

        Livewire::test(ViewPurchaseOrder::class, ['record' => $ancienPurchaseOrder->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Achat remplacé');
    }

    /**
     * 6. Isolation multi-lignes : un PurchaseOrder à deux lignes, une
     *    seule issue d'une ré-allocation — "Achat remplacé" n'apparaît
     *    qu'une seule fois.
     */
    public function test_reallocation_sur_une_ligne_naffecte_pas_lautre_ligne_du_meme_purchase_order(): void
    {
        [, $nouveauPurchaseOrder] = $this->createReallocatedPurchaseOrders();

        // Deuxième ligne manuelle (hors Dropshipping) ajoutée au même
        // PurchaseOrder que la ligne ré-allouée.
        $nouveauPurchaseOrder->items()->create([
            'product_id' => Product::factory()->create()->id,
            'quantity_ordered' => 2,
        ]);

        $response = Livewire::test(ViewPurchaseOrder::class, ['record' => $nouveauPurchaseOrder->getKey()])
            ->assertSuccessful();

        $response->assertSee('Achat remplacé');
        $response->assertSee('Achat direct');

        $html = $response->html();
        $this->assertSame(1, substr_count($html, 'Achat remplacé'));
    }

    /**
     * 7. Non-régression N+1 : comparaison DELTA entre une vue à 1 ligne
     *    et une vue à 5 lignes (dont 4 supplémentaires ré-allouées),
     *    plutôt qu'un seuil absolu fixe. Une page Filament ViewRecord
     *    complète (panel, permissions, navigation, sections multiples)
     *    a un coût de base élevé et sans rapport avec ce fichier (mesuré
     *    à 128 requêtes pour 1 ligne dans cet environnement) : un seuil
     *    absolu serait soit trop large pour détecter un vrai N+1, soit
     *    fragile au moindre changement ailleurs dans l'application. Le
     *    DELTA, lui, isole précisément le coût des 4 lignes
     *    supplémentaires sur la chaîne d'eager loading testée ici
     *    (allocation.replacesAllocation.purchaseOrderItem.purchaseOrder) :
     *    avec un chargement par palier (D2.11), ce delta reste faible
     *    et constant ; un N+1 réel le ferait croître avec le nombre de
     *    lignes (mesuré empiriquement à 13 requêtes pour 4 lignes
     *    supplémentaires avec l'implémentation actuelle).
     */
    public function test_affichage_ne_provoque_pas_de_n_plus_1(): void
    {
        [, $purchaseOrderUneLigne] = $this->createReallocatedPurchaseOrders('Fournisseur A1', 'Fournisseur A2');

        DB::enableQueryLog();
        Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrderUneLigne->fresh()->getKey()])
            ->assertSuccessful();
        $countUneLigne = count(DB::getQueryLog());
        DB::disableQueryLog();
        // disableQueryLog() ne vide pas le journal : sans ce flush, la
        // deuxième mesure ci-dessous s'ajouterait à celle-ci au lieu de
        // repartir de zéro, faussant complètement le delta.
        DB::flushQueryLog();

        [, $purchaseOrderCinqLignes] = $this->createReallocatedPurchaseOrders('Fournisseur B0', 'Fournisseur C0');

        foreach (range(1, 4) as $i) {
            [, $autrePurchaseOrder] = $this->createReallocatedPurchaseOrders("Fournisseur B{$i}", "Fournisseur C{$i}");

            // Regroupe artificiellement les lignes supplémentaires sur
            // le MÊME PurchaseOrder pour tester le passage à l'échelle
            // du nombre de LIGNES d'une seule vue, pas le nombre de
            // commandes distinctes.
            $autrePurchaseOrder->items()->first()->update(['purchase_order_id' => $purchaseOrderCinqLignes->id]);
        }

        DB::enableQueryLog();
        Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrderCinqLignes->fresh()->getKey()])
            ->assertSuccessful();
        $countCinqLignes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $delta = $countCinqLignes - $countUneLigne;

        // Seuil large et documenté plutôt qu'un nombre exact fragile
        // (même esprit que SalesOrdersTableSourcingOverviewTest) : 4
        // lignes supplémentaires ne doivent ajouter qu'une poignée de
        // requêtes de chargement par palier, jamais un multiple du
        // nombre de lignes (signature d'un N+1).
        $this->assertLessThan(
            20,
            $delta,
            "Delta de requêtes anormalement élevé ({$delta}) pour 4 lignes supplémentaires : suspicion de N+1 sur 'replaced_purchase_order'."
        );
    }
}
