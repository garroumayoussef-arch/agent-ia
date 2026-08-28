<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Notifications\LowStockAlertDigestNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D1/D2/D3/D6/D8, validés)
 * — SendLowStockAlerts, digest quotidien interne (canal database,
 * jamais un email pour cet événement), scopé par entrepôt pour un
 * manager (même règle que LowStockAlertByWarehouse, reproduite
 * localement — jamais réutilisée via Auth::user()).
 */
class LowStockAlertDigestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'reference' => 'REF-'.uniqid(), 'nom' => 'Maillot', 'categorie' => 'Maillots',
            'type' => 'Player Version', 'taille' => 'M', 'stock' => 0, 'prix_achat' => 10, 'prix_vente' => 20,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create(['name' => 'Entrepôt '.uniqid(), 'code' => 'w-'.uniqid()]);
    }

    public function test_un_manager_avec_stock_bas_dans_son_perimetre_recoit_un_digest(): void
    {
        Notification::fake();
        $warehouse = $this->makeWarehouse();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->makeProduct()->id, 'stock' => 2]);

        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach($warehouse->id);

        Artisan::call('notifications:low-stock-alerts');

        Notification::assertSentTo($manager, LowStockAlertDigestNotification::class);
    }

    /**
     * Même règle fail-closed que ScopesToOwnWarehouses (T19/T21, jamais
     * modifiée) : un manager sans entrepôt attribué ne voit rien, donc
     * ne reçoit jamais de digest, même si du stock bas existe ailleurs.
     */
    public function test_un_manager_sans_entrepot_attribue_ne_recoit_rien(): void
    {
        Notification::fake();
        $warehouse = $this->makeWarehouse();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->makeProduct()->id, 'stock' => 2]);

        $manager = User::factory()->create()->assignRole('manager');
        // Aucun $manager->warehouses()->attach(...).

        Artisan::call('notifications:low-stock-alerts');

        Notification::assertNotSentTo($manager, LowStockAlertDigestNotification::class);
    }

    public function test_un_admin_voit_tout_le_stock_bas_sans_restriction(): void
    {
        Notification::fake();
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $this->makeProduct()->id, 'stock' => 1]);
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $this->makeProduct()->id, 'stock' => 1]);

        $admin = User::factory()->create()->assignRole('admin');

        Artisan::call('notifications:low-stock-alerts');

        Notification::assertSentTo(
            $admin,
            LowStockAlertDigestNotification::class,
            function (LowStockAlertDigestNotification $notification) {
                return $notification->toDatabase($notification)['count'] === 2;
            },
        );
    }

    public function test_aucun_digest_si_aucun_stock_bas_dans_le_perimetre(): void
    {
        Notification::fake();
        $warehouse = $this->makeWarehouse();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->makeProduct()->id, 'stock' => 100]);

        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach($warehouse->id);

        Artisan::call('notifications:low-stock-alerts');

        Notification::assertNothingSent();
    }

    /**
     * D8 (validé) — au plus un digest par utilisateur et par jour civil :
     * un second déclenchement de la commande le même jour ne doit
     * jamais renvoyer un second digest.
     */
    public function test_lappel_repete_le_meme_jour_nenvoie_pas_deux_digests(): void
    {
        $warehouse = $this->makeWarehouse();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->makeProduct()->id, 'stock' => 2]);

        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach($warehouse->id);

        Artisan::call('notifications:low-stock-alerts');
        Artisan::call('notifications:low-stock-alerts');

        $this->assertSame(
            1,
            NotificationLog::where('notifiable_type', User::class)
                ->where('notifiable_id', $manager->id)
                ->where('event_type', 'low_stock_digest')
                ->count(),
        );
        $this->assertSame(1, $manager->notifications()->count());
    }
}
