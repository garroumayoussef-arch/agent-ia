<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItemReturn;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\SupplierProductSourcing;
use App\Models\Warehouse;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.4.7 — couverture de
 * SalesOrderItemAllocation::recordFor(), première capacité d'ÉCRITURE du
 * chantier. Aucune interface Filament, aucune autorisation en jeu ici :
 * tests au niveau modèle uniquement, comme SupplierSourcingResolverTest
 * (D2.4.6) ou SupplierProductSourcingTest (D1).
 *
 * Couvre les règles métier validées (manifeste D2.4.7) : délégation
 * exclusive à SupplierSourcingResolver::best() (aucune nouvelle règle de
 * sélection), une allocation par ligne (rejet explicite d'une seconde
 * tentative), échec explicite (jamais silencieux) en l'absence de
 * sourcing actif, contraintes restrictOnDelete, transversalité
 * inter-activités, et absence de tout effet de bord sur
 * PurchaseOrder/StockMovement/stock.
 */
class SalesOrderItemAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function createSourcing(Product $product, array $overrides = []): SupplierProductSourcing
    {
        return $product->supplierSourcings()->create(array_merge([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Construit une allocation intégralement éligible à la
     * ré-allocation D2.9 : commande fournisseur générée (D2.6),
     * intégralement réceptionnée, intégralement retournée au
     * fournisseur. Crée un entrepôt par défaut si nécessaire (requis par
     * PurchaseOrder::receive()/PurchaseOrderItemReturn::recordFor()).
     */
    private function makeFullyReturnedAllocation(int $quantity = 5, ?Supplier $supplier = null): SalesOrderItemAllocation
    {
        if (! Warehouse::where('is_default', true)->exists()) {
            Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
        }

        $product = Product::factory()->create();
        $this->createSourcing($product, $supplier ? ['supplier_id' => $supplier->id] : []);

        $salesOrder = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
        ]);
        $salesOrder->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));
        $purchaseOrder = $result['created'][0];
        $purchaseOrder->markAsOrdered();

        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => $quantity]);

        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), $quantity, now()->toDateString());

        return $allocation->fresh();
    }

    /*
     * =================================================================
     * recordFor() — sourcing actif unique
     * =================================================================
     */

    public function test_recordfor_alloue_au_sourcing_actif_du_produit(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $sourcing = $this->createSourcing($product);

        $allocation = SalesOrderItemAllocation::recordFor($item);

        $this->assertSame($item->id, $allocation->sales_order_item_id);
        $this->assertSame($sourcing->id, $allocation->supplier_product_sourcing_id);
        $this->assertSame($item->quantity_ordered, $allocation->quantity);
    }

    /*
     * =================================================================
     * recordFor() — priorité stricte au sourcing spécifique de la
     * variante (délégation exclusive au comportement déjà testé du
     * resolver, D2.4.6)
     * =================================================================
     */

    public function test_recordfor_alloue_au_sourcing_specifique_de_la_variante_en_priorite(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $item = SalesOrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        // Sourcing générique du produit : actif, mais ne doit PAS être
        // choisi puisqu'un sourcing spécifique actif existe.
        $this->createSourcing($product);

        $sourcingVariante = $variant->supplierSourcings()->create([
            'product_id' => $product->id,
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $allocation = SalesOrderItemAllocation::recordFor($item);

        $this->assertSame($sourcingVariante->id, $allocation->supplier_product_sourcing_id);
    }

    /*
     * =================================================================
     * recordFor() — aucun sourcing actif applicable : échec explicite,
     * jamais un retour null silencieux
     * =================================================================
     */

    public function test_recordfor_leve_une_exception_si_aucun_sourcing_actif_nest_applicable(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Aucun sourcing fournisseur actif n'est applicable à cette ligne.");

        SalesOrderItemAllocation::recordFor($item);
    }

    /*
     * =================================================================
     * recordFor() — une seule allocation par ligne (split multi-
     * fournisseur et réallocation automatique hors périmètre)
     * =================================================================
     */

    public function test_recordfor_rejette_une_seconde_allocation_sur_la_meme_ligne(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette ligne de commande a déjà une allocation.');

        SalesOrderItemAllocation::recordFor($item);
    }

    /*
     * =================================================================
     * Contraintes de suppression (restrictOnDelete)
     * =================================================================
     */

    public function test_suppression_du_sourcing_reference_par_une_allocation_est_bloquee(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $sourcing = $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->expectException(QueryException::class);

        $sourcing->delete();
    }

    public function test_suppression_dune_sales_order_item_allouee_est_bloquee(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        // quantity_shipped = 0 ici : c'est bien la contrainte de base
        // restrictOnDelete qui est testée, pas le garde-fou applicatif
        // de SalesOrderItem::deleting() (ligne déjà expédiée).
        $this->expectException(QueryException::class);

        $item->delete();
    }

    /*
     * =================================================================
     * Transversalité — aucune logique conditionnelle liée à `activity`
     * =================================================================
     */

    public function test_le_comportement_est_identique_pour_plusieurs_activites_utilisant_product(): void
    {
        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);
            $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
            $sourcing = $this->createSourcing($product);

            $allocation = SalesOrderItemAllocation::recordFor($item);

            $this->assertSame($sourcing->id, $allocation->supplier_product_sourcing_id);
        }
    }

    /*
     * =================================================================
     * Absence d'effet de bord — aucun StockMovement/PurchaseOrder, aucun
     * changement de stock
     * =================================================================
     */

    public function test_recordfor_ne_produit_aucun_effet_de_bord_sur_stock_ou_commandes(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        SalesOrderItemAllocation::recordFor($item);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    /*
     * =================================================================
     * D2.9 — reallocateFor() : ré-allocation manuelle vers un
     * fournisseur alternatif après retour intégral
     * =================================================================
     */

    public function test_reallocatefor_cree_une_nouvelle_allocation_qui_remplace_lancienne(): void
    {
        $current = $this->makeFullyReturnedAllocation(quantity: 7);
        $ancienFournisseurId = $current->supplierProductSourcing->supplier_id;

        // Deuxième fournisseur alternatif compatible, actif.
        $current->salesOrderItem->product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $nouvelle = SalesOrderItemAllocation::reallocateFor($current);

        $this->assertSame($current->id, $nouvelle->replaces_allocation_id);
        $this->assertSame($current->sales_order_item_id, $nouvelle->sales_order_item_id);
        $this->assertSame(7, $nouvelle->quantity);
        $this->assertNotSame($ancienFournisseurId, $nouvelle->supplierProductSourcing->supplier_id);
    }

    public function test_reallocatefor_ne_modifie_jamais_lallocation_remplacee(): void
    {
        $current = $this->makeFullyReturnedAllocation();
        $current->salesOrderItem->product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $champs = ['sales_order_item_id', 'supplier_product_sourcing_id', 'quantity', 'replaces_allocation_id'];
        $avant = $current->only($champs);
        $avant['updated_at'] = $current->updated_at->toDateTimeString();

        SalesOrderItemAllocation::reallocateFor($current);

        $fresh = $current->fresh();
        $apres = $fresh->only($champs);
        $apres['updated_at'] = $fresh->updated_at->toDateTimeString();

        $this->assertSame($avant, $apres);
    }

    public function test_reallocatefor_leve_une_exception_si_retour_non_integral(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $product = Product::factory()->create();
        $this->createSourcing($product);

        $salesOrder = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
        ]);
        $salesOrder->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());
        $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $purchaseOrder->markAsOrdered();
        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => 10]);

        // Retour PARTIEL uniquement (6 sur 10 reçus) : sum(returns) !== quantity_received.
        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), 6, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Le retour fournisseur n'est pas intégral pour cette allocation : la ré-allocation n'est pas possible.");

        SalesOrderItemAllocation::reallocateFor($allocation->fresh());
    }

    public function test_reallocatefor_leve_une_exception_si_rien_na_ete_recu(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $product = Product::factory()->create();
        $this->createSourcing($product);

        $salesOrder = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create(['sales_order_id' => $salesOrder->id, 'product_id' => $product->id]);
        $salesOrder->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());
        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        // quantity_received === 0 : le cas trivial 0 === 0 ne doit
        // JAMAIS être traité comme un retour intégral.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Le retour fournisseur n'est pas intégral pour cette allocation : la ré-allocation n'est pas possible.");

        SalesOrderItemAllocation::reallocateFor($allocation->fresh());
    }

    public function test_reallocatefor_leve_une_exception_si_aucune_purchase_order_item_generee(): void
    {
        $product = Product::factory()->create();
        $item = SalesOrderItem::factory()->create(['product_id' => $product->id]);
        $this->createSourcing($product);

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Cette allocation n'a pas encore de commande fournisseur générée : aucune ré-allocation possible.");

        SalesOrderItemAllocation::reallocateFor($allocation);
    }

    public function test_reallocatefor_leve_une_exception_si_aucun_fournisseur_alternatif(): void
    {
        // Un seul fournisseur actif (celui déjà utilisé) : aucune
        // alternative compatible.
        $current = $this->makeFullyReturnedAllocation();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Aucun fournisseur alternatif compatible n'est disponible pour cette ligne.");

        SalesOrderItemAllocation::reallocateFor($current);
    }

    public function test_reallocatefor_leve_une_exception_si_deja_remplacee(): void
    {
        $current = $this->makeFullyReturnedAllocation();
        $current->salesOrderItem->product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        SalesOrderItemAllocation::reallocateFor($current);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette allocation a déjà été remplacée par une ré-allocation.');

        SalesOrderItemAllocation::reallocateFor($current->fresh());
    }

    public function test_reallocatefor_exclut_uniquement_le_fournisseur_immediatement_precedent(): void
    {
        $fournisseurA = Supplier::factory()->create(['name' => fake()->company()]);
        $fournisseurB = Supplier::factory()->create(['name' => fake()->company()]);

        $allocation1 = $this->makeFullyReturnedAllocation(supplier: $fournisseurA);
        $product = $allocation1->salesOrderItem->product;

        // Fournisseur B : priorité meilleure (valeur plus basse) que A.
        $product->supplierSourcings()->create([
            'supplier_id' => $fournisseurB->id,
            'priority' => 1,
            'is_active' => true,
        ]);
        SupplierProductSourcing::where('supplier_id', $fournisseurA->id)->update(['priority' => 50]);

        // Ré-allocation 1 -> 2 : exclut A, retient B.
        $allocation2 = SalesOrderItemAllocation::reallocateFor($allocation1);
        $this->assertSame($fournisseurB->id, $allocation2->supplierProductSourcing->supplier_id);

        // La ligne 2 doit elle-même devenir éligible (achat généré,
        // reçu, intégralement retourné) avant une nouvelle ré-allocation.
        $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation2]))['created'][0];
        $purchaseOrder->markAsOrdered();
        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => $allocation2->quantity]);
        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), $allocation2->quantity, now()->toDateString());

        // Ré-allocation 2 -> 3 : exclut UNIQUEMENT B (l'allocation
        // immédiatement précédente) — A redevient éligible et doit être
        // reproposé puisqu'il n'est plus exclu (spécification validée,
        // point 4).
        $allocation3 = SalesOrderItemAllocation::reallocateFor($allocation2->fresh());
        $this->assertSame($fournisseurA->id, $allocation3->supplierProductSourcing->supplier_id);
    }

    public function test_ancienne_allocation_ne_peut_pas_etre_modifiee(): void
    {
        $current = $this->makeFullyReturnedAllocation();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Une allocation de sourcing ne peut pas être modifiée après son enregistrement.');

        $current->quantity = 999;
        $current->save();
    }

    public function test_ancienne_allocation_ne_peut_pas_etre_supprimee(): void
    {
        $current = $this->makeFullyReturnedAllocation();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Une allocation de sourcing ne peut pas être supprimée après son enregistrement.');

        $current->delete();
    }

    public function test_item_allocation_retourne_toujours_lallocation_active_apres_reallocation(): void
    {
        $current = $this->makeFullyReturnedAllocation();
        $current->salesOrderItem->product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        $nouvelle = SalesOrderItemAllocation::reallocateFor($current);

        $item = $current->salesOrderItem->fresh();
        $this->assertSame($nouvelle->id, $item->allocation->id);
        $this->assertNotSame($current->id, $item->allocation->id);
    }

    public function test_replaces_allocation_id_est_unique_en_base(): void
    {
        $current = $this->makeFullyReturnedAllocation();
        $current->salesOrderItem->product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);

        SalesOrderItemAllocation::reallocateFor($current);

        // Contournement direct de la garde applicative de
        // reallocateFor() : vérifie que la contrainte UNIQUE en base
        // (garde DÉFINITIVE, spécification validée) protège aussi contre
        // une insertion brute qui court-circuiterait le modèle.
        $this->expectException(QueryException::class);

        SalesOrderItemAllocation::withoutEvents(function () use ($current) {
            SalesOrderItemAllocation::create([
                'sales_order_item_id' => $current->sales_order_item_id,
                'supplier_product_sourcing_id' => $current->supplier_product_sourcing_id,
                'quantity' => $current->quantity,
                'replaces_allocation_id' => $current->id,
            ]);
        });
    }
}
