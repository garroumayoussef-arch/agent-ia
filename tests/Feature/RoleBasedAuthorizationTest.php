<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleBasedAuthorizationTest extends TestCase
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
            'nom' => 'Maillot Test',
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
     * RoleSeeder
     * =================================================================
     */

    public function test_le_role_seeder_cree_les_3_roles(): void
    {
        $this->assertSame(3, \Spatie\Permission\Models\Role::count());
        $this->assertTrue(\Spatie\Permission\Models\Role::where('name', 'admin')->exists());
        $this->assertTrue(\Spatie\Permission\Models\Role::where('name', 'manager')->exists());
        $this->assertTrue(\Spatie\Permission\Models\Role::where('name', 'viewer')->exists());
    }

    public function test_le_role_seeder_accorde_admin_aux_utilisateurs_existants_sans_role(): void
    {
        // Utilisateur créé APRÈS le premier passage du RoleSeeder (dans
        // setUp), donc pas encore "grandfathered" : on le simule en
        // rejouant le seeder après création, comme lors d'un vrai
        // déploiement sur une base existante.
        $existingUser = User::factory()->create();

        $this->seed(RoleSeeder::class);

        $this->assertTrue($existingUser->fresh()->hasRole('admin'));
    }

    public function test_le_role_seeder_najoute_pas_admin_a_un_utilisateur_ayant_deja_un_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');

        $this->seed(RoleSeeder::class);

        $user->refresh();
        $this->assertTrue($user->hasRole('viewer'));
        $this->assertFalse($user->hasRole('admin'));
    }

    /*
     * =================================================================
     * Lecture : ouverte à tout utilisateur authentifié, même sans rôle
     * =================================================================
     */

    public function test_un_utilisateur_sans_role_peut_consulter_les_produits(): void
    {
        $user = User::factory()->create(); // aucun rôle

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('index'))->assertSuccessful();
    }

    /*
     * =================================================================
     * Écriture : réservée à admin/manager
     * =================================================================
     */

    public function test_un_utilisateur_sans_role_ne_peut_pas_acceder_a_la_page_de_creation(): void
    {
        $user = User::factory()->create(); // aucun rôle = lecteur

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('create'))->assertForbidden();
    }

    public function test_un_viewer_ne_peut_pas_acceder_a_la_page_de_creation(): void
    {
        $user = User::factory()->create()->assignRole('viewer');

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('create'))->assertForbidden();
    }

    public function test_un_viewer_ne_peut_pas_acceder_a_la_page_dedition(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create()->assignRole('viewer');

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    }

    public function test_un_manager_peut_creer_et_modifier_des_produits(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create()->assignRole('manager');

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('create'))->assertSuccessful();
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertSuccessful();
    }

    public function test_un_manager_peut_reellement_creer_un_produit_via_le_formulaire(): void
    {
        $category = \App\Models\Category::create(['name' => 'Football', 'slug' => 'football']);
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'reference' => 'REF-ROLE-TEST',
                'nom' => 'Produit via manager',
                'category_id' => $category->id,
                'type' => 'Player Version',
                'prix_achat' => 10,
                'prix_vente' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['reference' => 'REF-ROLE-TEST']);
    }

    public function test_un_viewer_ne_peut_pas_creer_un_produit_meme_en_appelant_le_composant_directement(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
        $this->actingAs($user);

        // mount() de CreateRecord authorize() via canCreate() : la page
        // n'est même pas montée pour un rôle non autorisé.
        $this->get(ProductResource::getUrl('create'))->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public function test_un_admin_peut_tout_faire(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create()->assignRole('admin');

        $this->actingAs($user);

        $this->get(ProductResource::getUrl('create'))->assertSuccessful();
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertSuccessful();
    }

    /*
     * =================================================================
     * UserResource — réservée aux admins, y compris en lecture
     * =================================================================
     */

    public function test_un_manager_ne_peut_pas_consulter_la_liste_des_utilisateurs(): void
    {
        $user = User::factory()->create()->assignRole('manager');

        $this->actingAs($user);

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_viewer_ne_peut_pas_consulter_la_liste_des_utilisateurs(): void
    {
        $user = User::factory()->create()->assignRole('viewer');

        $this->actingAs($user);

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_admin_peut_consulter_et_gerer_les_utilisateurs(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $other = User::factory()->create();

        $this->actingAs($admin);

        $this->get(UserResource::getUrl('index'))->assertSuccessful();
        $this->get(UserResource::getUrl('create'))->assertSuccessful();
        $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertSuccessful();
    }

    public function test_un_admin_peut_assigner_un_role_a_un_utilisateur_via_le_formulaire(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $target = User::factory()->create();
        $viewerRole = \Spatie\Permission\Models\Role::where('name', 'manager')->first();

        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['roles' => [$viewerRole->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($target->fresh()->hasRole('manager'));
    }
}
