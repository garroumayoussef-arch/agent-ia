<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T12 — tests de l'action Filament "Nouveau transfert" sur
 * ListStockMovements (HasStockTransferAction), en complément des tests
 * backend de StockTransferTest.php.
 */
class StockTransferResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Produit T12',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt '.uniqid(),
            'code' => 'w-'.uniqid(),
        ], $attributes));
    }

    public function test_un_manager_peut_executer_un_transfert_via_laction(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        // Étape T19 (D4) — un manager restreint doit avoir LES DEUX
        // entrepôts (source et destination) dans son périmètre.
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', data: [
                'product_id' => $product->id,
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'quantity' => 4,
            ]);

        $this->assertSame(1, StockTransfer::count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(6, WarehouseStock::where('warehouse_id', $warehouseA->id)->value('stock'));
        $this->assertSame(4, WarehouseStock::where('warehouse_id', $warehouseB->id)->value('stock'));
    }

    public function test_un_transfert_sur_variante_fonctionne_via_laction(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-UI-T12',
            'stock' => 8,
            'status' => 'active',
        ]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 8]);

        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', data: [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'quantity' => 3,
            ]);

        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertSame(5, WarehouseStock::where('warehouse_id', $warehouseA->id)->where('product_variant_id', $variant->id)->value('stock'));
        $this->assertSame(3, WarehouseStock::where('warehouse_id', $warehouseB->id)->where('product_variant_id', $variant->id)->value('stock'));
    }

    public function test_laction_transfer_est_invisible_pour_un_role_non_autorise(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ListStockMovements::class)
            ->assertActionHidden('transfer');
    }

    public function test_laction_transfer_est_visible_pour_un_manager(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListStockMovements::class)
            ->assertActionVisible('transfer');
    }

    public function test_message_derreur_propre_sans_exception_brute_si_stock_insuffisant(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        // Étape T19 (D4) — les deux entrepôts sont dans le périmètre du
        // manager : ce test doit continuer à exercer le refus pour
        // stock insuffisant (T12), pas un refus d'autorisation (T19).
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
        $product = $this->makeProduct(['stock' => 2]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 2]);

        // L'appel ne doit PAS lever d'exception non gérée jusqu'au test
        // (StockTransfer::execute() est capturé par un try/catch dans
        // l'action, qui affiche une notification d'erreur à la place) :
        // le test réussit si aucune exception ne remonte ici, et si
        // aucune écriture n'a eu lieu malgré l'échec.
        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', data: [
                'product_id' => $product->id,
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'quantity' => 5, // > stock disponible dans l'entrepôt source
            ]);

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(2, WarehouseStock::where('warehouse_id', $warehouseA->id)->value('stock'));
    }

    public function test_laction_refuse_source_egale_destination_meme_si_envoye_directement(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        // Simule un navigateur qui contournerait le filtrage du
        // formulaire (options d'exclusion côté UI) et enverrait
        // directement source = destination : la barrière autoritaire
        // (StockTransfer::execute(), jamais confiance au client) doit
        // refuser, pas seulement le formulaire.
        $warehouse = $this->makeWarehouse();
        // Étape T19 — dans le périmètre du manager : ce test doit
        // continuer à exercer le refus pour source = destination (T12),
        // pas un refus d'autorisation (T19).
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        Livewire::test(ListStockMovements::class)
            ->callAction('transfer', data: [
                'product_id' => $product->id,
                'from_warehouse_id' => $warehouse->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]);

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame(5, WarehouseStock::where('warehouse_id', $warehouse->id)->value('stock'));
    }
}
