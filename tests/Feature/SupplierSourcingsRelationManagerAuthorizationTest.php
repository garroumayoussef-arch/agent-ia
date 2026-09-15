<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\SupplierSourcingsRelationManager;
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
 * Chantier Dropshipping, étape D2.4.5 — restriction admin/manager des
 * actions create/edit/delete/deleteAny de SupplierSourcingsRelationManager
 * (côté ProductResource). Symétrique de
 * SupplierProductSourcingsRelationManagerAuthorizationTest (D2.4.4, côté
 * SupplierResource) : même structure, mêmes 4 profils testés (admin,
 * manager, viewer, sans rôle), adaptée au formulaire côté Product
 * (supplier_id requis dans le formulaire, jamais product_id — déjà
 * déterminé par la relation supplierSourcings() du produit propriétaire).
 *
 * Couvre les 4 méthodes réellement consultées par Filament pour
 * autoriser les actions du tableau (get*AuthorizationResponse(), cf.
 * RelationManager::getDefaultActionAuthorizationResponse()), pas
 * seulement leurs enveloppes bool can*() — create/edit/delete via
 * assertions Filament sur la visibilité/utilisation réelle du bouton,
 * deleteAny via réflexion sur la méthode protected canDeleteAny() (aucune
 * DeleteBulkAction configurée dans ce RelationManager, donc aucun effet
 * observable via l'interface).
 */
class SupplierSourcingsRelationManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function mountRelationManager(Product $product): Testable
    {
        return Livewire::test(SupplierSourcingsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
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
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->mountRelationManager($product)
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['supplier_id' => $supplier->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_un_manager_voit_et_peut_utiliser_laction_create(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->mountRelationManager($product)
            ->assertTableActionVisible('create')
            ->callTableAction('create', data: ['supplier_id' => $supplier->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('supplier_product_sourcing', [
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_un_viewer_ne_voit_pas_laction_create(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->mountRelationManager($product)->assertTableActionHidden('create');
    }

    public function test_un_utilisateur_sans_role_ne_voit_pas_laction_create(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create()); // aucun rôle

        $this->mountRelationManager($product)->assertTableActionHidden('create');
    }

    /*
     * =================================================================
     * edit / delete (par fiche)
     * =================================================================
     */

    public function test_un_admin_voit_les_actions_edit_et_delete(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $product->supplierSourcings()->create(['supplier_id' => $supplier->id]);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->mountRelationManager($product)
            ->assertTableActionVisible('edit', $sourcing)
            ->assertTableActionVisible('delete', $sourcing);
    }

    public function test_un_manager_voit_les_actions_edit_et_delete(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $product->supplierSourcings()->create(['supplier_id' => $supplier->id]);
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->mountRelationManager($product)
            ->assertTableActionVisible('edit', $sourcing)
            ->assertTableActionVisible('delete', $sourcing);
    }

    public function test_un_viewer_ne_voit_pas_les_actions_edit_et_delete(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $product->supplierSourcings()->create(['supplier_id' => $supplier->id]);
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->mountRelationManager($product)
            ->assertTableActionHidden('edit', $sourcing)
            ->assertTableActionHidden('delete', $sourcing);
    }

    public function test_un_utilisateur_sans_role_ne_voit_pas_les_actions_edit_et_delete(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sourcing = $product->supplierSourcings()->create(['supplier_id' => $supplier->id]);
        $this->actingAs(User::factory()->create()); // aucun rôle

        $this->mountRelationManager($product)
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
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->assertTrue($this->callProtectedMethod(
            $this->mountRelationManager($product)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->assertTrue($this->callProtectedMethod(
            $this->mountRelationManager($product)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        $this->assertFalse($this->callProtectedMethod(
            $this->mountRelationManager($product)->instance(),
            'canDeleteAny'
        ));

        $this->actingAs(User::factory()->create()); // aucun rôle
        $this->assertFalse($this->callProtectedMethod(
            $this->mountRelationManager($product)->instance(),
            'canDeleteAny'
        ));
    }
}
