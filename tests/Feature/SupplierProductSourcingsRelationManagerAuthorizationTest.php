<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\RelationManagers\SupplierSourcingsRelationManager;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierProductSourcingsRelationManager;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.4.4 — restriction admin/manager des
 * actions create/edit/delete/deleteAny de SupplierProductSourcingsRelationManager
 * (côté SupplierResource). Volontairement séparé de
 * SupplierProductSourcingsRelationManagerTest (D2.4.1), qui exclut
 * explicitement les permissions de son périmètre ("sujet traité
 * séparément après le câblage fonctionnel D2.4.2/D2.4.3").
 *
 * Couvre les 4 méthodes réellement consultées par Filament pour
 * autoriser les actions du tableau (get*AuthorizationResponse(), cf.
 * RelationManager::getDefaultActionAuthorizationResponse()), pas
 * seulement leurs enveloppes bool can*() — create/edit/delete via
 * assertions Filament sur la visibilité réelle du bouton, deleteAny via
 * réflexion sur la méthode protected canDeleteAny() (aucune
 * DeleteBulkAction configurée dans ce RelationManager, donc aucun effet
 * observable via l'interface).
 */
class SupplierProductSourcingsRelationManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function mountRelationManager(Supplier $supplier): Testable
    {
        return Livewire::test(SupplierProductSourcingsRelationManager::class, [
            'ownerRecord' => $supplier,
            'pageClass' => EditSupplier::class,
        ]);
    }

    private function callProtectedMethod(object $instance, string $method): mixed
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($instance);
    }

    /*
     * =================================================================
     * create
     * =================================================================
     */

    public function test_un_admin_voit_et_peut_utiliser_laction_create(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->mountRelationManager($supplier)
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['product_id' => $product->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_un_manager_voit_et_peut_utiliser_laction_create(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->mountRelationManager($supplier)
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['product_id' => $product->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_un_viewer_ne_voit_pas_laction_create(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->mountRelationManager($supplier)->assertTableActionHidden('create');
    }

    public function test_un_utilisateur_sans_role_ne_voit_pas_laction_create(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $this->actingAs(User::factory()->create()); // aucun rôle

        $this->mountRelationManager($supplier)->assertTableActionHidden('create');
    }

    /*
     * =================================================================
     * edit / delete (par fiche)
     * =================================================================
     */

    public function test_un_admin_voit_les_actions_edit_et_delete(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $supplier->productSourcings()->create(['product_id' => Product::factory()->create()->id]);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->mountRelationManager($supplier)
            ->assertTableActionVisible('edit', $sourcing)
            ->assertTableActionVisible('delete', $sourcing);
    }

    public function test_un_manager_voit_les_actions_edit_et_delete(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $supplier->productSourcings()->create(['product_id' => Product::factory()->create()->id]);
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->mountRelationManager($supplier)
            ->assertTableActionVisible('edit', $sourcing)
            ->assertTableActionVisible('delete', $sourcing);
    }

    public function test_un_viewer_ne_voit_pas_les_actions_edit_et_delete(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $supplier->productSourcings()->create(['product_id' => Product::factory()->create()->id]);
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->mountRelationManager($supplier)
            ->assertTableActionHidden('edit', $sourcing)
            ->assertTableActionHidden('delete', $sourcing);
    }

    public function test_un_utilisateur_sans_role_ne_voit_pas_les_actions_edit_et_delete(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $supplier->productSourcings()->create(['product_id' => Product::factory()->create()->id]);
        $this->actingAs(User::factory()->create()); // aucun rôle

        $this->mountRelationManager($supplier)
            ->assertTableActionHidden('edit', $sourcing)
            ->assertTableActionHidden('delete', $sourcing);
    }

    /*
     * =================================================================
     * deleteAny — aucune DeleteBulkAction configurée dans ce
     * RelationManager (cf. table()) : vérifié directement via réflexion
     * sur canDeleteAny() (protected, héritée de
     * InteractsWithRelationshipTable), alimentée par notre override de
     * getDeleteAnyAuthorizationResponse().
     * =================================================================
     */

    public function test_candeleteany_autorise_admin_et_manager_refuse_viewer_et_sans_role(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);

        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->assertTrue($this->callProtectedMethod(
            $this->mountRelationManager($supplier)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->assertTrue($this->callProtectedMethod(
            $this->mountRelationManager($supplier)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        $this->assertFalse($this->callProtectedMethod(
            $this->mountRelationManager($supplier)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()); // aucun rôle
        $this->assertFalse($this->callProtectedMethod(
            $this->mountRelationManager($supplier)->instance(),
            'canDeleteAny'
        ));
    }

    /*
     * =================================================================
     * Non-régression D2.3 (Product) — explicitement hors périmètre de
     * D2.4.4 : le RelationManager côté Product ne doit redéfinir aucune
     * des 4 méthodes d'autorisation (elles doivent rester celles,
     * inchangées, de la classe de base Filament).
     * =================================================================
     */

    public function test_le_relationmanager_cote_product_ne_redefinit_aucune_methode_dautorisation(): void
    {
        foreach ([
            'getCreateAuthorizationResponse',
            'getEditAuthorizationResponse',
            'getDeleteAuthorizationResponse',
            'getDeleteAnyAuthorizationResponse',
        ] as $method) {
            $reflection = new ReflectionMethod(SupplierSourcingsRelationManager::class, $method);

            $this->assertNotSame(
                SupplierSourcingsRelationManager::class,
                $reflection->getDeclaringClass()->getName(),
                "Le RelationManager côté Product ne doit pas redéfinir {$method} (hors périmètre D2.4.4)."
            );
        }
    }

    /**
     * Symétrique du test précédent : preuve explicite que les 4
     * overrides attendus sont bien portés par
     * SupplierProductSourcingsRelationManager elle-même (et pas
     * silencieusement absorbés/oubliés).
     */
    public function test_le_relationmanager_cote_supplier_redefinit_bien_les_4_methodes_dautorisation(): void
    {
        foreach ([
            'getCreateAuthorizationResponse',
            'getEditAuthorizationResponse',
            'getDeleteAuthorizationResponse',
            'getDeleteAnyAuthorizationResponse',
        ] as $method) {
            $reflection = new ReflectionMethod(SupplierProductSourcingsRelationManager::class, $method);

            $this->assertSame(
                SupplierProductSourcingsRelationManager::class,
                $reflection->getDeclaringClass()->getName(),
            );
        }
    }
}
