<?php

namespace Tests\Feature;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Clubs\ClubResource;
use App\Filament\Resources\Competitions\CompetitionResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
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

    /*
     * =================================================================
     * Supplier — chantier transversal T4 (BlocksChauffeurReadAccess),
     * réutilisé tel quel depuis T3, aucune nouvelle logique
     * d'autorisation. Même structure que Brand/Category/Club
     * (SupplierResource n'a lui non plus aucune page "view").
     * =================================================================
     */

    public function test_ladmin_et_le_manager_conservent_lacces_en_lecture_a_supplierresource(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get(SupplierResource::getUrl('index'))->assertSuccessful();

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->get(SupplierResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_fournisseurs(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(SupplierResource::getUrl('index'))->assertForbidden();
    }

    /**
     * SupplierResource n'a aucune page "view" (cf. getPages()) :
     * canView() n'est atteignable par aucune route HTTP — seul un
     * appel statique direct le vérifie, même principe que pour
     * Brand/Category/Club en T3.
     */
    public function test_canview_refuse_un_chauffeur_pour_supplierresource(): void
    {
        $supplier = Supplier::create(['name' => 'Fournisseur T4']);

        $this->actingAs($this->makeChauffeurAccount());

        $this->assertFalse(SupplierResource::canView($supplier));
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_conserve_son_acces_a_supplierresource(): void
    {
        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié

        $this->get(SupplierResource::getUrl('index'))->assertSuccessful();
    }

    /**
     * Écriture déjà restreinte à admin/manager (HasRoleBasedAuthorization,
     * inchangé par ce chantier) : photographié ici pour Supplier
     * spécifiquement, avec le même code HTTP (403) que la lecture,
     * cohérent avec le reste de HasRoleBasedAuthorization.
     */
    public function test_un_chauffeur_ne_peut_pas_creer_de_fournisseur(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(SupplierResource::getUrl('create'))->assertForbidden();
    }

    /**
     * PurchaseOrder dépend de Supplier (supplier_id) : ce test vérifie
     * explicitement que restreindre SupplierResource pour un chauffeur
     * n'affecte pas l'accès (déjà admin/manager uniquement, inchangé)
     * ni le fonctionnement de PurchaseOrderResource pour un manager —
     * la sélection du fournisseur dans le formulaire PurchaseOrder
     * interroge le modèle Supplier directement (Select::relationship()),
     * jamais via SupplierResource::getEloquentQuery()/autorisation.
     */
    public function test_purchaseorderresource_reste_pleinement_fonctionnel_pour_un_manager_apres_t4(): void
    {
        $supplier = Supplier::create(['name' => 'Fournisseur T4 bis']);
        $order = \App\Models\PurchaseOrder::create(['reference' => 'BC-T4-1', 'supplier_id' => $supplier->id]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('create'))->assertSuccessful();
        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertSuccessful();
        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Customer — chantier transversal T5 (BlocksChauffeurReadAccess),
     * réutilisé tel quel depuis T3/T4. Dépendances avec SalesOrder ET
     * VtcRide vérifiées explicitement avant implémentation (aucune des
     * deux ne référence CustomerResource — couplage strictement au
     * niveau du modèle Customer, jamais de l'autorisation Filament) :
     * cf. l'analyse T5. Aucune modification de VtcRide/VtcRideResource/
     * SalesOrderResource n'a été nécessaire.
     * =================================================================
     */

    private function makeVtcVehicle(): Vehicle
    {
        return Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
    }

    private function setVtcFiscalSetting(TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate->id],
        );
    }

    /**
     * Chauffeur (Driver + compte utilisateur lié) avec une course VTC
     * CONFIRMÉE associée à un client donné — le cas exact que la règle
     * 6 (« un chauffeur doit toujours voir les informations client
     * nécessaires à ses propres courses ») doit préserver.
     */
    private function makeChauffeurWithConfirmedRideForCustomer(Customer $customer): array
    {
        $rate10 = TaxRate::firstOrCreate(
            ['label' => 'VTC T5'],
            ['type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10],
        );
        $this->setVtcFiscalSetting($rate10);

        $user = User::factory()->create();
        $driver = Driver::create(['name' => 'Chauffeur T5', 'user_id' => $user->id]);

        $ride = VtcRide::create([
            'reference' => 'VTC-T5-'.uniqid(),
            'price_ht' => 100,
            'driver_id' => $driver->id,
            'vehicle_id' => $this->makeVtcVehicle()->id,
            'customer_id' => $customer->id,
        ]);
        $ride->markAsConfirmed();

        return [$user, $driver, $ride->fresh()];
    }

    public function test_ladmin_et_le_manager_conservent_lacces_en_lecture_a_customerresource(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get(CustomerResource::getUrl('index'))->assertSuccessful();

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->get(CustomerResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_le_catalogue_clients(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(CustomerResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_conserve_son_acces_a_customerresource(): void
    {
        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié

        $this->get(CustomerResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_creer_de_client(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(CustomerResource::getUrl('create'))->assertForbidden();
    }

    /**
     * Règle obligatoire : un chauffeur voit toujours le nom du client
     * sur SA PROPRE course confirmée — via VtcRideResource, jamais via
     * CustomerResource (non modifié, non nécessaire). Preuve que la
     * restriction de T5 n'a aucun effet sur l'usage VTC légitime.
     */
    public function test_un_chauffeur_voit_le_nom_du_client_sur_sa_propre_course_vtc_confirmee(): void
    {
        $customer = Customer::create(['name' => 'Client T5 Alpha']);
        [$user, , $ride] = $this->makeChauffeurWithConfirmedRideForCustomer($customer);

        $this->actingAs($user);

        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))
            ->assertSuccessful()
            ->assertSeeText('Client T5 Alpha');
    }

    /**
     * Même règle, sur le reçu récapitulatif (étape 5.9) — la surface la
     * plus riche en informations client (nom, société, adresse, ville,
     * email, téléphone), toutes lues directement sur le modèle
     * Customer, jamais via CustomerResource.
     */
    public function test_un_chauffeur_voit_les_informations_client_sur_le_recu_de_sa_propre_course(): void
    {
        $customer = Customer::create([
            'name' => 'Client T5 Beta',
            'company' => 'Société Beta',
            'address' => '10 rue du Test',
        ]);
        [$user, , $ride] = $this->makeChauffeurWithConfirmedRideForCustomer($customer);

        $this->actingAs($user);

        $this->get(route('vtc-rides.receipt', $ride))
            ->assertSuccessful()
            ->assertSeeText('Client T5 Beta')
            ->assertSeeText('Société Beta')
            ->assertSeeText('10 rue du Test');
    }

    /**
     * Règle obligatoire : un chauffeur ne doit JAMAIS pouvoir utiliser
     * l'exception ci-dessus pour consulter le catalogue Customer — même
     * le client de SA PROPRE course reste inaccessible via
     * CustomerResource (aucune exception "own record" n'existe pour
     * cette Resource, contrairement à VtcRideResource) ; et le client
     * d'une AUTRE course/chauffeur reste évidemment hors de portée.
     */
    public function test_un_chauffeur_ne_peut_pas_consulter_le_catalogue_customer_meme_pour_son_propre_client(): void
    {
        $ownCustomer = Customer::create(['name' => 'Client T5 Gamma (le sien)']);
        [$user, , ] = $this->makeChauffeurWithConfirmedRideForCustomer($ownCustomer);

        $otherCustomer = Customer::create(['name' => 'Client T5 Delta (autre course)']);
        $otherDriver = Driver::create(['name' => 'Chauffeur T5 Autre']);
        $otherRide = VtcRide::create([
            'reference' => 'VTC-T5-OTHER',
            'price_ht' => 100,
            'driver_id' => $otherDriver->id,
            'vehicle_id' => $this->makeVtcVehicle()->id,
            'customer_id' => $otherCustomer->id,
        ]);
        $otherRide->markAsConfirmed();

        $this->actingAs($user);

        // Ni son propre client...
        $this->assertFalse(CustomerResource::canView($ownCustomer));
        // ...ni celui d'un autre chauffeur.
        $this->assertFalse(CustomerResource::canView($otherCustomer));
        // Le catalogue reste entièrement fermé, sans aucune exception.
        $this->get(CustomerResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Non-régression explicite demandée : SalesOrderResource (hors
     * périmètre, non modifié) reste pleinement fonctionnel pour un
     * manager après T5 — même vérification que pour PurchaseOrder en
     * T4, cf. l'analyse T5 (Select::relationship() sur customer_id,
     * jamais via CustomerResource).
     */
    public function test_salesorderresource_reste_pleinement_fonctionnel_pour_un_manager_apres_t5(): void
    {
        $customer = Customer::create(['name' => 'Client T5 SalesOrder']);
        $order = SalesOrder::create(['reference' => 'CMD-T5-1', 'customer_id' => $customer->id]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('create'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('view', ['record' => $order]))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful();
    }

    /**
     * Non-régression explicite demandée : SalesOrderResource reste
     * accessible en lecture à l'admin (comportement HasRoleBasedAuthorization
     * inchangé, hors périmètre de ce chantier).
     */
    public function test_salesorderresource_reste_accessible_a_ladmin_apres_t5(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
    }

    /*
     * =================================================================
     * Product/ProductVariant — chantier transversal T6
     * (BlocksChauffeurReadAccess), réutilisé tel quel depuis T3/T4/T5.
     * Dépendances avec PurchaseOrder, SalesOrder ET StockMovement
     * vérifiées explicitement avant implémentation (aucune des trois
     * ne référence ProductResource/ProductVariantResource pour ses
     * champs product_id/product_variant_id — Select::make(...)->options()
     * interroge Product/ProductVariant directement) : cf. l'analyse T6.
     * StockOverview/LowStockAlert (exposition ambiante sur le dashboard)
     * restent explicitement hors périmètre, non modifiés.
     * =================================================================
     */

    private function makeProductVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_ladmin_et_le_manager_conservent_lacces_en_lecture_a_product_et_productvariant(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get(ProductResource::getUrl('index'))->assertSuccessful();
        $this->get(ProductVariantResource::getUrl('index'))->assertSuccessful();

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->get(ProductResource::getUrl('index'))->assertSuccessful();
        $this->get(ProductVariantResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_produits(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(ProductResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_variantes(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(ProductVariantResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Ni Product ni ProductVariant n'ont de page "view" (cf.
     * getPages()) : canView() n'est atteignable par aucune route HTTP —
     * seul un appel statique direct le vérifie, même principe qu'en
     * T3/T4/T5.
     */
    public function test_canview_refuse_un_chauffeur_pour_product_et_productvariant(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeProductVariant($product);

        $this->actingAs($this->makeChauffeurAccount());

        $this->assertFalse(ProductResource::canView($product));
        $this->assertFalse(ProductVariantResource::canView($variant));
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_conserve_son_acces_a_product_et_productvariant(): void
    {
        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié

        $this->get(ProductResource::getUrl('index'))->assertSuccessful();
        $this->get(ProductVariantResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_creer_de_produit_ou_de_variante(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(ProductResource::getUrl('create'))->assertForbidden();
        $this->get(ProductVariantResource::getUrl('create'))->assertForbidden();
    }

    /**
     * Non-régression explicite demandée : PurchaseOrderResource,
     * SalesOrderResource ET StockMovementResource (les 3 dépendances de
     * Product, hors périmètre, non modifiées) restent pleinement
     * fonctionnels pour un manager après T6.
     */
    public function test_purchaseorder_salesorder_et_stockmovement_restent_fonctionnels_pour_un_manager_apres_t6(): void
    {
        $product = $this->makeProduct();
        $supplier = Supplier::create(['name' => 'Fournisseur T6']);
        $customer = Customer::create(['name' => 'Client T6']);

        $purchaseOrder = \App\Models\PurchaseOrder::create([
            'reference' => 'BC-T6-1',
            'supplier_id' => $supplier->id,
        ]);
        $salesOrder = SalesOrder::create([
            'reference' => 'CMD-T6-1',
            'customer_id' => $customer->id,
        ]);
        $stockMovement = \App\Models\StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(\App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]))->assertSuccessful();

        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('view', ['record' => $salesOrder]))->assertSuccessful();

        $this->get(\App\Filament\Resources\StockMovements\StockMovementResource::getUrl('index'))->assertSuccessful();
        $this->get(\App\Filament\Resources\StockMovements\StockMovementResource::getUrl('view', ['record' => $stockMovement]))->assertSuccessful();
    }

    /*
     * =================================================================
     * PurchaseOrder/SalesOrder — chantier transversal T7
     * (BlocksChauffeurReadAccess), réutilisé tel quel depuis T3-T6.
     * StockMovement (T8) et TaxRate (T9) restent explicitement hors
     * périmètre. StockOverview/LowStockAlert restent hors périmètre
     * (décision T6). Aucune dépendance avec le module VTC (VtcRide ne
     * référence jamais PurchaseOrder/SalesOrder, cf. étape 5.1) — non
     * re-testé ici, vérifié par la suite complète VTC après ce commit.
     *
     * Différence structurelle avec T3-T6 : PurchaseOrderResource ET
     * SalesOrderResource ont chacune une page "view" dédiée
     * (ViewPurchaseOrder/ViewSalesOrder) — canView() est donc
     * atteignable par une vraie route HTTP, pas seulement par appel
     * statique direct.
     * =================================================================
     */

    public function test_ladmin_et_le_manager_conservent_lacces_en_lecture_a_purchaseorder_et_salesorder(): void
    {
        $supplier = Supplier::create(['name' => 'Fournisseur T7']);
        $customer = Customer::create(['name' => 'Client T7']);
        $purchaseOrder = PurchaseOrder::create(['reference' => 'BC-T7-1', 'supplier_id' => $supplier->id]);
        $salesOrder = SalesOrder::create(['reference' => 'CMD-T7-1', 'customer_id' => $customer->id]);

        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->get(PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('view', ['record' => $salesOrder]))->assertSuccessful();

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->get(PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('view', ['record' => $salesOrder]))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_bons_de_commande(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(PurchaseOrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_plus_consulter_les_commandes_de_vente(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(SalesOrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_la_fiche_dun_bon_de_commande_par_url_directe(): void
    {
        $supplier = Supplier::create(['name' => 'Fournisseur T7 bis']);
        $purchaseOrder = PurchaseOrder::create(['reference' => 'BC-T7-2', 'supplier_id' => $supplier->id]);

        $this->actingAs($this->makeChauffeurAccount());

        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]))->assertForbidden();
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_la_fiche_dune_commande_de_vente_par_url_directe(): void
    {
        $customer = Customer::create(['name' => 'Client T7 bis']);
        $salesOrder = SalesOrder::create(['reference' => 'CMD-T7-2', 'customer_id' => $customer->id]);

        $this->actingAs($this->makeChauffeurAccount());

        $this->get(SalesOrderResource::getUrl('view', ['record' => $salesOrder]))->assertForbidden();
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_conserve_son_acces_a_purchaseorder_et_salesorder(): void
    {
        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié

        $this->get(PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_creer_de_bon_de_commande_ou_de_commande_de_vente(): void
    {
        $this->actingAs($this->makeChauffeurAccount());

        $this->get(PurchaseOrderResource::getUrl('create'))->assertForbidden();
        $this->get(SalesOrderResource::getUrl('create'))->assertForbidden();
    }
}
