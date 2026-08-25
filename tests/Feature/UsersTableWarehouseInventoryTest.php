<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T22 — inventaire des viewers sans entrepôt attribué, ajouté à
 * UsersTable.php (colonne "Entrepôts attribués" + filtre "Viewers sans
 * entrepôt"). Purement additif et en LECTURE SEULE : aucune attribution
 * automatique, aucun changement aux règles T19-T21 (ScopesToOwnWarehouses
 * n'est ni composé ni modifié ici), aucune migration.
 */
class UsersTableWarehouseInventoryTest extends TestCase
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

    /*
     * =================================================================
     * Colonne "Entrepôts attribués" (D3 — visible pour tous)
     * =================================================================
     */

    public function test_la_colonne_affiche_aucun_pour_un_utilisateur_sans_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $viewer = User::factory()->create()->assignRole('viewer');

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$viewer])
            ->assertTableColumnStateSet('warehouses_summary', 'Aucun', record: $viewer);
    }

    public function test_la_colonne_affiche_les_noms_des_entrepots_attribues(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse(['name' => 'Entrepôt Nord']);
        $warehouseB = $this->makeWarehouse(['name' => 'Entrepôt Sud']);
        $viewer = User::factory()->create()->assignRole('viewer');
        $viewer->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('warehouses_summary', 'Entrepôt Nord, Entrepôt Sud', record: $viewer);
    }

    /**
     * D3 — la colonne concerne TOUS les utilisateurs, pas seulement les
     * viewers : un manager sans entrepôt doit aussi apparaître "Aucun".
     */
    public function test_la_colonne_sapplique_aussi_a_un_manager_sans_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $manager = User::factory()->create()->assignRole('manager');

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('warehouses_summary', 'Aucun', record: $manager);
    }

    /*
     * =================================================================
     * Filtre "Viewers sans entrepôt" (D2 — role strictement 'viewer')
     * =================================================================
     */

    public function test_le_filtre_inclut_un_viewer_pur_sans_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $viewerWithout = User::factory()->create()->assignRole('viewer');
        $warehouse = $this->makeWarehouse();
        $viewerWith = User::factory()->create()->assignRole('viewer');
        $viewerWith->warehouses()->attach($warehouse);

        Livewire::test(ListUsers::class)
            ->filterTable('viewers_without_warehouse')
            ->assertCanSeeTableRecords([$viewerWithout])
            ->assertCanNotSeeTableRecords([$viewerWith]);
    }

    public function test_le_filtre_exclut_un_manager_sans_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $manager = User::factory()->create()->assignRole('manager');

        Livewire::test(ListUsers::class)
            ->filterTable('viewers_without_warehouse')
            ->assertCanNotSeeTableRecords([$manager]);
    }

    /**
     * D2 — décision explicite : un cumul de rôles (viewer + manager)
     * n'est PAS un "pur" viewer au sens de cet inventaire, même sans
     * entrepôt attribué.
     */
    public function test_le_filtre_exclut_un_utilisateur_cumulant_viewer_et_manager(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $mixed = User::factory()->create();
        $mixed->assignRole(['viewer', 'manager']);

        Livewire::test(ListUsers::class)
            ->filterTable('viewers_without_warehouse')
            ->assertCanNotSeeTableRecords([$mixed]);
    }

    public function test_le_filtre_narien_a_afficher_si_tous_les_viewers_ont_un_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouse = $this->makeWarehouse();
        $viewer = User::factory()->create()->assignRole('viewer');
        $viewer->warehouses()->attach($warehouse);

        Livewire::test(ListUsers::class)
            ->filterTable('viewers_without_warehouse')
            ->assertCountTableRecords(0);
    }

    /*
     * =================================================================
     * Sécurité — inchangé, cette fonctionnalité reste strictement admin
     * =================================================================
     */

    public function test_un_manager_na_toujours_pas_acces_a_lecran_utilisateurs(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_viewer_na_toujours_pas_acces_a_lecran_utilisateurs(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }
}
