<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Driver;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier "bon de retour" (décisions 1 à 6, validées) — le PDF d'un
 * retour physique fournisseur est généré exclusivement depuis les
 * données déjà figées de PurchaseOrderItemReturn/PurchaseOrderItem,
 * jamais recalculé depuis Product/Supplier/CompanySettings au moment
 * du téléchargement — même principe que
 * CreditNoteLineReturnPdfControllerTest, appliqué au retour
 * fournisseur.
 *
 * Décision 2 (validée) : informations entreprise lues EN DIRECT depuis
 * CompanySettings::current() — jamais figées sur le retour.
 */
class PurchaseOrderItemReturnPdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        CompanySettings::current()->update([
            'legal_name' => 'Magarrou',
            'legal_form' => 'SASU',
            'address' => '1 rue du Sport',
            'postal_code' => '75000',
            'city' => 'Paris',
            'country' => 'France',
            'siren' => '111222333',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
            'vat_number' => 'FR11111222333',
        ]);
    }

    private function makeReturn(array $supplierAttributes = []): PurchaseOrderItemReturn
    {
        $supplier = Supplier::create(array_merge([
            'name' => 'Fournisseur PDF Retour',
        ], $supplierAttributes));

        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot PDF Retour Fournisseur',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-'.uniqid(), 'supplier_id' => $supplier->id]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);

        $order->markAsOrdered();
        $order->fresh()->receive([$item->id => 5]);

        return PurchaseOrderItemReturn::recordFor($item->fresh(), 2, now()->toDateString());
    }

    public function test_le_pdf_est_genere_avec_succes_pour_un_admin(): void
    {
        $return = $this->makeReturn();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $response = $this->get(route('purchase-order-item-returns.pdf', $return));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_pdf_reste_accessible_pour_un_viewer(): void
    {
        $return = $this->makeReturn();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(route('purchase-order-item-returns.pdf', $return))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_telecharger_un_bon_de_retour_fournisseur(): void
    {
        $return = $this->makeReturn();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur PDF Retour Fournisseur', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(route('purchase-order-item-returns.pdf', $return))->assertNotFound();
    }

    /**
     * Décision 2 (validée) — régénérable même après une modification
     * ultérieure de CompanySettings ou du fournisseur (aucun snapshot
     * dédié introduit sur PurchaseOrderItemReturn).
     */
    public function test_le_pdf_reste_regenerable_apres_modification_des_parametres_entreprise_et_du_fournisseur(): void
    {
        $return = $this->makeReturn(['name' => 'Nom Original Fournisseur']);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        CompanySettings::current()->update(['legal_name' => 'Nouvelle Raison Sociale']);
        $return->purchaseOrderItem->purchaseOrder->supplier->update(['name' => 'Nom Modifié Fournisseur']);

        $response = $this->get(route('purchase-order-item-returns.pdf', $return));

        $response->assertSuccessful();
    }
}
