<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\SupplierSourcingsRelationManager;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierProductSourcingsRelationManager;
use App\Models\Product;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierProductSourcingIdentityActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    public static function interfaces(): array
    {
        return [[true], [false]];
    }

    private function mountSourcingManager(array $s, bool $product)
    {
        return Livewire::test($product ? SupplierSourcingsRelationManager::class : SupplierProductSourcingsRelationManager::class, [
            'ownerRecord' => $product ? $s['product'] : $s['supplier'],
            'pageClass' => $product ? EditProduct::class : EditSupplier::class,
        ]);
    }

    #[DataProvider('interfaces')]
    public function test_edition_operationnelle_permises_et_identite_gelee(bool $product): void
    {
        $s = SupplierProductSourcingIdentityTest::scenario();
        $this->mountSourcingManager($s, $product)->mountTableAction('edit', $s['source'])
            ->assertFormFieldDisabled($product ? 'supplier_id' : 'product_id')
            ->assertFormFieldDisabled('product_variant_id')
            ->setTableActionData(['notes' => 'Modifié', 'is_active' => false, 'supplier_cost' => 18])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Modifié', $s['source']->fresh()->notes);
        $this->assertFalse($s['source']->fresh()->is_active);
        $this->assertSame('18.00', $s['source']->fresh()->supplier_cost);
    }

    #[DataProvider('interfaces')]
    public function test_identite_manipulee_refuse_aussi_la_partie_operationnelle(bool $product): void
    {
        $s = SupplierProductSourcingIdentityTest::scenario();
        $other = $product ? Supplier::factory()->create(['name' => fake()->company()]) : Product::factory()->create();
        $before = $s['source']->fresh()->getAttributes();
        $this->mountSourcingManager($s, $product)->callTableAction('edit', $s['source'], data: [
            $product ? 'supplier_id' : 'product_id' => $other->id, 'notes' => 'interdit',
        ])->assertNotified('Modification refusée');
        $this->assertSame($before, $s['source']->fresh()->getAttributes());
    }

    #[DataProvider('interfaces')]
    public function test_allocation_apres_ouverture_refuse_la_modification_mixte(bool $product): void
    {
        $s = SupplierProductSourcingIdentityTest::scenario(false);
        $other = $product ? Supplier::factory()->create(['name' => fake()->company()]) : Product::factory()->create();
        $component = $this->mountSourcingManager($s, $product)->mountTableAction('edit', $s['source']);
        SalesOrderItemAllocation::recordFor($s['item']);
        $before = $s['source']->fresh()->getAttributes();
        $component->setTableActionData([$product ? 'supplier_id' : 'product_id' => $other->id, 'notes' => 'interdit'])
            ->callMountedTableAction()->assertNotified('Modification refusée');
        $this->assertSame($before, $s['source']->fresh()->getAttributes());
    }

    #[DataProvider('interfaces')]
    public function test_fiche_libre_editable_et_permissions_preservees(bool $product): void
    {
        $s = SupplierProductSourcingIdentityTest::scenario(false);
        $other = $product ? Supplier::factory()->create(['name' => fake()->company()]) : Product::factory()->create();
        $field = $product ? 'supplier_id' : 'product_id';
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->mountSourcingManager($s, $product)->callTableAction('edit', $s['source'], data: [$field => $other->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame($other->id, $s['source']->fresh()->getAttribute($field));
        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        $this->mountSourcingManager($s, $product)->assertTableActionHidden('edit', $s['source']);
    }
}
