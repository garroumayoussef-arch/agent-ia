<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T23 — action "Générer la facture" sur SalesOrder (D2 : visible
 * uniquement si intégralement expédiée). Vérifie explicitement qu'elle
 * porte sa propre garde de rôle (SalesOrderResource::canEdit()),
 * contrairement à confirmOrder/shipOrder/cancelOrder qui n'en ont pas
 * (cf. le commentaire de tête de HasSalesOrderWorkflowActions) — un
 * viewer ne doit jamais pouvoir émettre une facture, même depuis
 * ViewSalesOrder qu'il peut consulter.
 */
class SalesOrderInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        TaxRate::create([
            'label' => 'TVA 20%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_sale' => true,
            'is_active' => true,
        ]);

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

    private function makeProduct(): Product
    {
        return Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Action',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client Action',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
    }

    private function makeDraftOrderWithItem(): array
    {
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $this->makeCustomer()->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->makeProduct()->id,
            'quantity_ordered' => 2,
            'unit_price' => 20,
        ]);

        return [$order, $item];
    }

    public function test_laction_est_invisible_sur_une_commande_confirmee_non_expediee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        [$order] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('generateInvoice');
    }

    public function test_laction_est_visible_pour_un_manager_sur_une_commande_shipped(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        // Étape T19 (D2, fail-closed) — le manager doit avoir l'entrepôt
        // par défaut dans son périmètre pour pouvoir expédier.
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('generateInvoice');
    }

    /**
     * Sécurité — un viewer peut consulter ViewSalesOrder (lecture
     * ouverte), mais l'action "Générer la facture" doit y rester
     * invisible malgré tout : garde de rôle explicite et délibérée
     * (voir le commentaire de tête du fichier).
     */
    public function test_laction_reste_invisible_pour_un_viewer_meme_sur_une_commande_shipped(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        // Étape T19 (D2, fail-closed) — le manager doit avoir l'entrepôt
        // par défaut dans son périmètre pour pouvoir expédier.
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('generateInvoice');
    }

    public function test_appeler_laction_genere_effectivement_une_facture(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('generateInvoice');

        $this->assertSame(1, Invoice::where('sales_order_id', $order->id)->count());
    }

    public function test_laction_generer_disparait_et_telecharger_apparait_une_fois_la_facture_emise(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);
        Invoice::generateFromSalesOrder($order->fresh());

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('generateInvoice')
            ->assertActionVisible('downloadInvoice');
    }

    /**
     * Étape T25-B — appel direct de mountAction() (pas le helper de
     * test callAction(), qui pré-vérifie lui-même assertActionVisible()
     * et ne testerait donc jamais le contournement réel) : reproduit un
     * appel Livewire forgé, indépendant de ce que l'interface affiche.
     * ->authorize() doit bloquer réellement l'exécution, pas seulement
     * masquer le bouton.
     */
    public function test_un_viewer_ne_peut_pas_generer_une_facture_par_appel_direct_de_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'generateInvoice');

        $this->assertSame(0, Invoice::where('sales_order_id', $order->id)->count());
    }
}
