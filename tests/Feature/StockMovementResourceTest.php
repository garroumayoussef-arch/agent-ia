<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T16 — colonne entrepôt, filtre entrepôt (table) et champ
 * entrepôt (Infolist) sur StockMovementResource. Périmètre strictement
 * limité à l'affichage : aucun modèle, migration ni autorisation
 * modifiés (voir StockMovementTest.php/RoleBasedAuthorizationTest.php,
 * inchangés).
 */
class StockMovementResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // StockMovement::creating() résout systématiquement un
        // entrepôt (T11b) — un entrepôt par défaut doit exister.
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
            'nom' => 'Produit T16',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    /*
     * =================================================================
     * Colonne entrepôt (table)
     * =================================================================
     */

    public function test_la_colonne_entrepot_affiche_le_bon_entrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16']);
        // Étape T20 — le manager doit avoir cet entrepôt dans son périmètre pour le voir en lecture.
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$movement])
            ->assertSee('Entrepôt A');
    }

    /*
     * =================================================================
     * Filtre entrepôt (table)
     * =================================================================
     */

    public function test_le_filtre_par_entrepot_reduit_correctement_la_liste(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16-filter']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t16-filter']);
        // Étape T20 — les deux entrepôts sont dans le périmètre du manager :
        // ce test doit continuer à exercer le FILTRE lui-même, pas le
        // scoping T20 (qui exclurait B de toute façon si non attribué).
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 0]);

        $movementA = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $movementB = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseB->id,
            'type' => 'purchase',
            'quantity' => 3,
        ]);

        Livewire::test(ListStockMovements::class)
            ->filterTable('warehouse_id', $warehouseA->id)
            ->assertCanSeeTableRecords([$movementA])
            ->assertCanNotSeeTableRecords([$movementB]);
    }

    /*
     * =================================================================
     * Champ entrepôt (Infolist / page de détail)
     * =================================================================
     */

    public function test_la_fiche_de_detail_affiche_lentrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16-view']);
        // Étape T20 — le manager doit avoir cet entrepôt dans son périmètre :
        // sinon la page "view" (résolue via getEloquentQuery()) renverrait 404.
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        Livewire::test(ViewStockMovement::class, ['record' => $movement->getKey()])
            ->assertSee('Entrepôt A');
    }

    /*
     * =================================================================
     * Non-régression — mouvements liés à un transfert (T12) toujours
     * listés correctement, entrepôt visible pour les deux jambes.
     * =================================================================
     */

    public function test_les_mouvements_transfer_out_et_transfer_in_affichent_leur_entrepot_respectif(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = Warehouse::create(['name' => 'Entrepôt Source', 'code' => 'source-t16']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt Destination', 'code' => 'dest-t16']);
        // Étape T19 (D4) — les deux entrepôts sont dans le périmètre du manager.
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 10]);

        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        $transfer = \App\Models\StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        Livewire::test(ListStockMovements::class)
            ->assertSee('Entrepôt Source')
            ->assertSee('Entrepôt Destination');

        $this->assertSame(2, $transfer->stockMovements()->count());
    }

    /*
     * =================================================================
     * Étape T17 — libellés transfer_out/transfer_in (table + Infolist)
     * =================================================================
     */

    public function test_la_table_affiche_les_libelles_dedies_transfer_out_et_transfer_in(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = Warehouse::create(['name' => 'Entrepôt Source', 'code' => 'source-t17']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt Destination', 'code' => 'dest-t17']);
        // Étape T19 (D4) — les deux entrepôts sont dans le périmètre du manager.
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 10]);

        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        \App\Models\StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        Livewire::test(ListStockMovements::class)
            ->assertSee('Transfert sortant')
            ->assertSee('Transfert entrant')
            // Le texte brut (non traduit) ne doit plus apparaître seul.
            ->assertDontSeeText('transfer_out')
            ->assertDontSeeText('transfer_in');
    }

    public function test_la_fiche_de_detail_affiche_le_libelle_dedie_transfer_out(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = Warehouse::create(['name' => 'Entrepôt Source', 'code' => 'source-t17-view']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt Destination', 'code' => 'dest-t17-view']);
        // Étape T19 (D4) — les deux entrepôts sont dans le périmètre du manager.
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 10]);

        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        $transfer = \App\Models\StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $transferOutMovement = $transfer->stockMovements()->where('type', 'transfer_out')->firstOrFail();

        Livewire::test(ViewStockMovement::class, ['record' => $transferOutMovement->getKey()])
            ->assertSee('Transfert sortant')
            ->assertDontSeeText('transfer_out');
    }

    /**
     * Non-régression : les 5 types existants conservent exactement
     * leur libellé actuel après l'ajout des cas transfer_out/transfer_in.
     */
    public function test_les_libelles_des_types_existants_restent_inchanges(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t17-existants']);
        // Étape T20 — le manager doit avoir cet entrepôt dans son périmètre pour le voir en lecture.
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5]);
        StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'adjustment', 'quantity' => 3]);

        Livewire::test(ListStockMovements::class)
            ->assertSee('🟢 Achat')
            ->assertSee('🟠 Ajustement');
    }

    /*
     * =================================================================
     * Finition "retour physique fournisseur" — libellé dédié dans la
     * table + option de filtre pour 'return_to_supplier' (périmètre
     * strictement en lecture : aucune règle métier, aucun modèle, aucune
     * migration modifiés — cf. StockMovement.php/PurchaseOrderItemReturnTest.php,
     * inchangés).
     * =================================================================
     */

    public function test_la_table_affiche_le_libelle_dedie_retour_fournisseur(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-retour-fournisseur']);
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 10]);

        StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'return_to_supplier',
            'quantity' => 4,
        ]);

        Livewire::test(ListStockMovements::class)
            ->assertSee('Retour fournisseur')
            // Le slug technique brut ne doit plus apparaître seul.
            ->assertDontSeeText('return_to_supplier');
    }

    public function test_le_filtre_par_type_isole_les_retours_fournisseurs(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-retour-fournisseur-filtre']);
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 10]);

        $returnMovement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'return_to_supplier',
            'quantity' => 4,
        ]);

        $purchaseMovement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        Livewire::test(ListStockMovements::class)
            ->filterTable('type', 'return_to_supplier')
            ->assertCanSeeTableRecords([$returnMovement])
            ->assertCanNotSeeTableRecords([$purchaseMovement]);
    }

    /**
     * Non-régression : les options de filtre déjà en place (purchase,
     * sale, return, adjustment, inventory) continuent d'isoler
     * correctement leurs mouvements après l'ajout de return_to_supplier/
     * transfer_out/transfer_in à la liste des options.
     */
    public function test_le_filtre_par_type_continue_disoler_les_types_existants(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-filtre-existant']);
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 10]);

        $purchaseMovement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $adjustmentMovement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'adjustment',
            'quantity' => 3,
        ]);

        Livewire::test(ListStockMovements::class)
            ->filterTable('type', 'purchase')
            ->assertCanSeeTableRecords([$purchaseMovement])
            ->assertCanNotSeeTableRecords([$adjustmentMovement]);
    }
}
