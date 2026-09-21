<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\SalesOrderItemAllocation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesOrderCancelledPurchaseRecoveryActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    public static function pages(): array
    {
        return [[ViewSalesOrder::class], [EditSalesOrder::class]];
    }

    #[DataProvider('pages')]
    public function test_confirmation_explicite_puis_reprise_sans_achat(string $page): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $component = Livewire::test($page, ['record' => $s['sale']->id])
            ->mountAction('recoverCancelledPurchase');
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
        $component->assertMountedActionModalSee($s['purchase']->reference)
            ->assertMountedActionModalSee($s['alternative']->supplier->name)
            ->setActionData(['allocations' => [$s['allocation']->id]])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('sales_order_item_allocations', 2);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertNotNull($s['allocation']->fresh()->replacedBy);
    }

    #[DataProvider('pages')]
    public function test_viewer_ne_peut_pas_executer_meme_par_appel_direct(string $page): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        if ($page === EditSalesOrder::class) {
            Livewire::test($page, ['record' => $s['sale']->id])->assertForbidden();
        } else {
            Livewire::test($page, ['record' => $s['sale']->id])
                ->callAction('recoverCancelledPurchase', data: ['allocations' => [$s['allocation']->id]]);
        }
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
    }

    public function test_admin_peut_reprendre(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])
            ->callAction('recoverCancelledPurchase', data: ['allocations' => [$s['allocation']->id]]);
        $this->assertDatabaseCount('sales_order_item_allocations', 2);
    }

    public function test_confirmation_perimee_par_changement_alternative_est_refusee(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $component = Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])->mountAction('recoverCancelledPurchase');
        $s['alternative']->update(['is_active' => false]);
        $component->setActionData(['allocations' => [$s['allocation']->id]])->callMountedAction();
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_confirmation_perimee_par_annulation_vente_est_refusee(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $component = Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])->mountAction('recoverCancelledPurchase');
        $s['sale']->cancel();
        $component->setActionData(['allocations' => [$s['allocation']->id]])->callMountedAction();
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
    }

    public function test_identifiant_etranger_et_confirmation_absente_sont_refuses(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $other = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])
            ->callAction('recoverCancelledPurchase', data: ['allocations' => [$other['allocation']->id]]);
        $this->assertDatabaseCount('sales_order_item_allocations', 2);
        $component = Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])->mountAction('recoverCancelledPurchase');
        session()->forget('cancelled-purchase-recovery.'.$component->get('cancelledPurchaseRecoveryToken'));
        $component->setActionData(['allocations' => [$s['allocation']->id]])->callMountedAction();
        $this->assertDatabaseCount('sales_order_item_allocations', 2);
    }

    public function test_un_echec_ne_bloque_pas_les_autres_lignes_selectionnees(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        $other = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        \Illuminate\Support\Facades\DB::table('sales_order_items')->where('id', $other['item']->id)
            ->update(['sales_order_id' => $s['sale']->id]);
        $component = Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])->mountAction('recoverCancelledPurchase');
        $s['alternative']->update(['is_active' => false]);
        $component->setActionData(['allocations' => [$s['allocation']->id, $other['allocation']->id]])->callMountedAction();
        $this->assertNull($s['allocation']->fresh()->replacedBy);
        $this->assertNotNull($other['allocation']->fresh()->replacedBy);
        $this->assertDatabaseCount('sales_order_item_allocations', 3);
    }

    public function test_action_invisible_pour_les_statuts_vente_interdits(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        foreach (['draft', 'cancelled', 'partially_shipped', 'shipped'] as $status) {
            \Illuminate\Support\Facades\DB::table('sales_orders')->where('id', $s['sale']->id)->update(['status' => $status]);
            Livewire::test(ViewSalesOrder::class, ['record' => $s['sale']->id])->assertActionHidden('recoverCancelledPurchase');
        }
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
    }
}
