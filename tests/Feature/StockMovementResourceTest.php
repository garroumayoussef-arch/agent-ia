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
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16']);
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
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16-filter']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t16-filter']);
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
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t16-view']);
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
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouseA = Warehouse::create(['name' => 'Entrepôt Source', 'code' => 'source-t16']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt Destination', 'code' => 'dest-t16']);
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
}
