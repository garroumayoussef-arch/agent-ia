<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T19 (D7) — relation many-to-many User <-> Warehouse (pivot
 * warehouse_user) et son exposition dans UserForm. Ne couvre PAS
 * l'effet du rattachement sur les autorisations (voir
 * WarehousePermissionScopingTest.php) : uniquement la relation et sa
 * persistance via le formulaire Filament.
 */
class WarehouseUserRelationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt '.uniqid(),
            'code' => 'w-'.uniqid(),
        ], $attributes));
    }

    public function test_un_utilisateur_peut_etre_rattache_a_plusieurs_entrepots(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();

        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

        $this->assertCount(2, $user->warehouses()->get());
        $this->assertTrue($user->warehouses->contains($warehouseA));
        $this->assertTrue($user->warehouses->contains($warehouseB));
    }

    public function test_la_relation_est_bidirectionnelle(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $warehouse = $this->makeWarehouse();

        $user->warehouses()->attach($warehouse);

        $this->assertTrue($warehouse->users->contains($user));
    }

    public function test_detacher_un_utilisateur_ne_supprime_ni_lutilisateur_ni_lentrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $warehouse->delete();

        $this->assertNotNull($user->fresh());
        $this->assertCount(0, $user->warehouses()->get());
    }

    public function test_supprimer_lutilisateur_ne_supprime_pas_lentrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $user->delete();

        $this->assertNotNull($warehouse->fresh());
    }

    /*
     * =================================================================
     * UserForm — persistance via le formulaire Filament (admin only)
     * =================================================================
     */

    public function test_la_creation_dun_utilisateur_via_le_formulaire_persiste_les_entrepots_attribues(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Manager Entrepôt',
                'email' => 'manager.entrepot@example.test',
                'password' => 'password',
                'warehouses' => [$warehouseA->id, $warehouseB->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'manager.entrepot@example.test')->firstOrFail();

        $this->assertCount(2, $user->warehouses()->get());
    }

    public function test_editer_un_utilisateur_permet_de_modifier_ses_entrepots_attribues(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $user = User::factory()->create()->assignRole('manager');
        $user->warehouses()->attach($warehouseA);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['warehouses' => [$warehouseB->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertCount(1, $user->warehouses);
        $this->assertTrue($user->warehouses->contains($warehouseB));
        $this->assertFalse($user->warehouses->contains($warehouseA));
    }
}
