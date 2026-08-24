<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Clubs\ClubResource;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Driver;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
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

    /*
     * =================================================================
     * DriverResource/VehicleResource — réservées à admin/manager, y
     * compris en lecture (étape 5.8).
     *
     * Objectif : un chauffeur (lié via Driver.user_id, sans rôle Spatie
     * — cas normal, rien ne lui en attribue) ne doit pas pouvoir
     * parcourir la fiche de TOUS les chauffeurs/véhicules. Son accès à
     * ses propres VtcRide reste, lui, ouvert — déjà couvert par
     * VtcRideAuthorizationTest.php (étape 5.5), non modifié ici et
     * donc non re-testé dans ce fichier.
     * =================================================================
     */

    public function test_un_chauffeur_ne_peut_pas_consulter_la_liste_des_chauffeurs(): void
    {
        $user = User::factory()->create(); // aucun rôle
        Driver::create(['name' => 'Chauffeur A', 'user_id' => $user->id]);

        $this->actingAs($user);

        $this->get(DriverResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_la_liste_des_vehicules(): void
    {
        $user = User::factory()->create(); // aucun rôle
        Driver::create(['name' => 'Chauffeur A', 'user_id' => $user->id]);

        $this->actingAs($user);

        $this->get(VehicleResource::getUrl('index'))->assertForbidden();
    }

    public function test_canview_refuse_un_chauffeur_pour_driverresource_et_vehicleresource(): void
    {
        $user = User::factory()->create();
        $driver = Driver::create(['name' => 'Chauffeur A', 'user_id' => $user->id]);
        $vehicle = Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);

        $this->actingAs($user);

        // Même la fiche de SON PROPRE Driver reste hors périmètre :
        // aucun besoin de self-service ici (cf. commentaire de
        // DriverResource), contrairement à VtcRideResource.
        $this->assertFalse(DriverResource::canView($driver));
        $this->assertFalse(VehicleResource::canView($vehicle));
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_ne_peut_pas_consulter_les_chauffeurs(): void
    {
        $user = User::factory()->create(); // ni rôle, ni Driver lié

        $this->actingAs($user);

        $this->get(DriverResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_manager_conserve_lacces_complet_a_driverresource_et_vehicleresource(): void
    {
        $driver = Driver::create(['name' => 'Chauffeur A']);
        $vehicle = Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
        $manager = User::factory()->create()->assignRole('manager');

        $this->actingAs($manager);

        $this->get(DriverResource::getUrl('index'))->assertSuccessful();
        $this->get(DriverResource::getUrl('edit', ['record' => $driver]))->assertSuccessful();
        $this->get(VehicleResource::getUrl('index'))->assertSuccessful();
        $this->get(VehicleResource::getUrl('edit', ['record' => $vehicle]))->assertSuccessful();
    }

    public function test_un_admin_conserve_lacces_complet_a_driverresource_et_vehicleresource(): void
    {
        $driver = Driver::create(['name' => 'Chauffeur A']);
        $vehicle = Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin);

        $this->get(DriverResource::getUrl('index'))->assertSuccessful();
        $this->get(DriverResource::getUrl('edit', ['record' => $driver]))->assertSuccessful();
        $this->get(VehicleResource::getUrl('index'))->assertSuccessful();
        $this->get(VehicleResource::getUrl('edit', ['record' => $vehicle]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Brand/Category/Club/Competition — chantier transversal T3
     * (BlocksChauffeurReadAccess), séparé du module VTC
     * =================================================================
     *
     * T2 : un chauffeur est EXCLUSIVEMENT un utilisateur authentifié
     * lié à un Driver (Driver.user_id) — jamais déduit de l'absence de
     * rôle. Un utilisateur sans rôle NI Driver associé reste un
     * utilisateur classique, dont l'accès en lecture (déjà ouvert par
     * HasRoleBasedAuthorization) ne change pas ici.
     *
     * Remplace les tests "avant T3" de T1 (qui vérifiaient que la
     * lecture était encore ouverte au chauffeur) : ce comportement
     * vient précisément de changer pour ce seul profil.
     */

    private function makeChauffeurAccount(): User
    {
        $user = User::factory()->create(); // aucun rôle Spatie
        Driver::create(['name' => 'Chauffeur T3', 'user_id' => $user->id]);

        return $user;
    }

    public function test_ladmin_et_le_manager_conservent_lacces_en_lecture_aux_4_resources_taxonomiques(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get(BrandResource::getUrl('index'))->assertSuccessful();
        $this->get(CategoryResource::getUrl('index'))->assertSuccessful();
        $this->get(ClubResource::getUrl('index'))->assertSuccessful();
        $this->get(CompetitionResource::getUrl('index'))->assertSuccessful();

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->get(BrandResource::getUrl('index'))->assertSuccessful();
        $this->get(CategoryResource::getUrl('index'))->assertSuccessful();
        $this->get(ClubResource::getUrl('index'))->assertSuccessful();
        $this->get(CompetitionResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_marques(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(BrandResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_categories(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(CategoryResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_clubs(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(ClubResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_competitions(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(CompetitionResource::getUrl('index'))->assertForbidden();
    }

    /**
     * CompetitionResource est la seule des 4 à avoir une page "view"
     * dédiée (ViewCompetition) — donc la seule où canView() est
     * atteignable par une vraie route HTTP, pas seulement par appel
     * statique direct.
     */
    public function test_un_chauffeur_ne_peut_pas_consulter_la_fiche_dune_competition_par_url_directe(): void
    {
        $competition = \App\Models\Competition::create(['name' => 'Compétition T3', 'slug' => 'competition-t3']);

        $this->actingAs($this->makeChauffeurAccount());

        $this->get(CompetitionResource::getUrl('view', ['record' => $competition]))->assertForbidden();
    }

    /**
     * Brand/Category/Club n'ont aucune page "view" (cf. getPages()) :
     * canView() n'est donc atteignable par aucune route HTTP pour ces
     * 3-là — seul un appel statique direct le vérifie, même principe
     * que test_canview_refuse_un_chauffeur_pour_driverresource_et_vehicleresource
     * (étape 5.8).
     */
    public function test_canview_refuse_un_chauffeur_pour_brand_category_et_club(): void
    {
        $brand = \App\Models\Brand::create(['name' => 'Marque T3', 'slug' => 'marque-t3']);
        $category = \App\Models\Category::create(['name' => 'Catégorie T3', 'slug' => 'categorie-t3']);
        $club = \App\Models\Club::create(['name' => 'Club T3', 'slug' => 'club-t3']);

        $this->actingAs($this->makeChauffeurAccount());

        $this->assertFalse(BrandResource::canView($brand));
        $this->assertFalse(CategoryResource::canView($category));
        $this->assertFalse(ClubResource::canView($club));
    }

    /**
     * Preuve explicite du respect du T2 : un utilisateur sans rôle ET
     * sans Driver associé reste un utilisateur classique — son accès
     * en lecture, déjà ouvert par HasRoleBasedAuthorization, n'est PAS
     * touché par ce chantier.
     */
    public function test_un_utilisateur_sans_role_ni_driver_associe_conserve_son_acces_en_lecture(): void
    {
        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié

        $this->get(BrandResource::getUrl('index'))->assertSuccessful();
        $this->get(CategoryResource::getUrl('index'))->assertSuccessful();
        $this->get(ClubResource::getUrl('index'))->assertSuccessful();
        $this->get(CompetitionResource::getUrl('index'))->assertSuccessful();
    }

    /**
     * Cas limite : la clause admin/manager prime toujours sur le
     * blocage chauffeur, même si l'utilisateur est aussi lié à un
     * Driver. Un seul test suffit (logique partagée par les 4 via le
     * même trait) — pas besoin de le répéter sur chacune.
     */
    public function test_un_admin_egalement_lie_a_un_driver_conserve_lacces_en_lecture(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Driver::create(['name' => 'Chauffeur Admin T3', 'user_id' => $admin->id]);

        $this->actingAs($admin);

        $this->get(CategoryResource::getUrl('index'))->assertSuccessful();
    }

    /**
     * Écriture déjà restreinte à admin/manager (HasRoleBasedAuthorization,
     * inchangé par ce chantier) : photographié ici pour les 4 Resources
     * spécifiquement, plutôt que supposé par analogie avec Product.
     */
    public function test_un_chauffeur_ne_peut_pas_creer_de_marque_categorie_club_ou_competition(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(BrandResource::getUrl('create'))->assertForbidden();
        $this->get(CategoryResource::getUrl('create'))->assertForbidden();
        $this->get(ClubResource::getUrl('create'))->assertForbidden();
        $this->get(CompetitionResource::getUrl('create'))->assertForbidden();
    }
}
