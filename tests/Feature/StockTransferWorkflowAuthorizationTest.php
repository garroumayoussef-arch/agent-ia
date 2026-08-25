<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T26 — transferAction() (StockTransfer) n'avait qu'une garde
 * ->visible(StockMovementResource::canCreate()) : simple affichage,
 * jamais réellement évaluée par Filament lors d'un appel direct/forgé
 * de l'action (resolveAction() appelle directement la méthode PHP, sans
 * jamais consulter isVisible()).
 *
 * Ces tests appellent directement mountAction()/callMountedAction() —
 * pas le helper de test callAction(), qui pré-vérifie lui-même
 * assertActionVisible() et ne testerait donc jamais le contournement
 * réel — pour reproduire exactement un appel Livewire direct/forgé,
 * indépendant de ce que l'interface affiche.
 */
class StockTransferWorkflowAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeProductWithStock(Warehouse $source): Product
    {
        // Le stock global du produit doit lui aussi être suffisant : la
        // validation de StockMovement (transfer_out) s'appuie sur lui en
        // plus de la ligne warehouse_stocks — même convention que dans
        // StockTransferTest (makeProduct(['stock' => ...])).
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Autorisation',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 10,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        WarehouseStock::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'stock' => 10]);

        return $product;
    }

    public function test_un_viewer_ne_peut_pas_effectuer_un_transfert_par_appel_direct_de_laction(): void
    {
        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-'.uniqid()]);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-'.uniqid()]);
        $product = $this->makeProductWithStock($warehouseA);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ListStockMovements::class)
            ->call('mountAction', 'transfer');

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame(10, WarehouseStock::where('warehouse_id', $warehouseA->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(0, (int) (WarehouseStock::where('warehouse_id', $warehouseB->id)->where('product_id', $product->id)->value('stock') ?? 0));
    }

    /**
     * Contrôle de non-régression (règle 7) — un manager doit pouvoir
     * effectuer un transfert exactement comme avant l'ajout de la garde
     * ->authorize().
     */
    public function test_un_manager_peut_toujours_effectuer_un_transfert_via_laction(): void
    {
        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-'.uniqid()]);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-'.uniqid()]);
        $product = $this->makeProductWithStock($warehouseA);

        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $this->actingAs($manager);

        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', data: [
                'product_id' => $product->id,
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'quantity' => 4,
            ]);

        $this->assertSame(1, StockTransfer::count());
        $this->assertSame(6, WarehouseStock::where('warehouse_id', $warehouseA->id)->where('product_id', $product->id)->value('stock'));
        $this->assertSame(4, WarehouseStock::where('warehouse_id', $warehouseB->id)->where('product_id', $product->id)->value('stock'));
    }
}
