<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 2.6.4 — non-régression TEXTE-À-TEXTE de
 * InvoiceLine.variant_description (Invoice::generateFromSalesOrder(),
 * cf. app/Models/Invoice.php), AVANT toute migration de sa construction
 * vers ProductVariant::attributeMirrorValue().
 *
 * Invoice.php n'est PAS encore migré au moment où ce test est écrit :
 * ce test caractérise le comportement ACTUEL —
 * implode(' / ', array_filter([size, color, sku])) ?: null — pour un
 * jeu de variantes représentatif, et doit rester intégralement vert,
 * avec EXACTEMENT les mêmes chaînes, une fois Invoice.php migré.
 *
 * ProductVariant.sku est une colonne obligatoire (unique, non nullable
 * en base — cf. migration create_product_variants_table) : le cas
 * "sans sku" du manifeste de l'étape ne peut donc jamais se produire
 * pour une ligne QUI A une variante — il est couvert ici par le cas
 * "aucune variante du tout" (product_variant_id null), seul cas réel où
 * $variant->sku est absent de la construction.
 *
 * Une InvoiceLine est immuable dès sa création (portée par
 * l'immuabilité d'Invoice — cf. Invoice::booted(), déjà testée par
 * ailleurs) : sa valeur ne peut structurellement jamais être réécrite
 * après émission, ce qui satisfait la garantie "aucune facture déjà
 * émise n'est réécrite rétroactivement" — ce test n'a donc pas besoin
 * de la re-vérifier indépendamment.
 */
class InvoiceVariantDescriptionAttributeTest extends TestCase
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

        $settings = CompanySettings::current();
        $settings->update([
            'legal_name' => 'Magarrou',
            'legal_form' => 'SASU',
            'address' => '1 rue du Sport',
            'postal_code' => '75000',
            'city' => 'Paris',
            'country' => 'France',
            'siren' => '111222333',
            'siret' => '11122233300010',
            'rcs_city' => 'Paris',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
            'vat_number' => 'FR11111222333',
            'recovery_indemnity_amount' => 40,
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot 2.6.4 Facture',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'status' => 'active',
            'stock' => 100,
        ], $attributes));
    }

    /**
     * Un seul SalesOrder à 5 lignes, une par combinaison représentative,
     * facturé en un seul appel : caractérise les 5 textes produits par
     * la construction ACTUELLE de variant_description, ligne par ligne.
     */
    public function test_variant_description_texte_a_texte_pour_un_jeu_de_variantes_representatif(): void
    {
        $customer = Customer::create([
            'name' => 'Client 2.6.4',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => '2 avenue des Clients',
            'postal_code' => '69000',
            'city' => 'Lyon',
            'country' => 'France',
        ]);

        // a) Taille seule (sku toujours présent, colonne obligatoire).
        $productSizeOnly = $this->makeProduct(['reference' => 'REF-A-'.uniqid()]);
        $variantSizeOnly = $this->makeVariant($productSizeOnly, ['sku' => 'INV-A', 'size' => '42']);

        // b) Couleur seule.
        $productColorOnly = $this->makeProduct(['reference' => 'REF-B-'.uniqid()]);
        $variantColorOnly = $this->makeVariant($productColorOnly, ['sku' => 'INV-B', 'color' => 'Rouge']);

        // c) Taille + couleur.
        $productBoth = $this->makeProduct(['reference' => 'REF-C-'.uniqid()]);
        $variantBoth = $this->makeVariant($productBoth, ['sku' => 'INV-C', 'size' => '42', 'color' => 'Rouge']);

        // d) Ni taille ni couleur (seul le sku, obligatoire, reste).
        $productNeither = $this->makeProduct(['reference' => 'REF-D-'.uniqid()]);
        $variantNeither = $this->makeVariant($productNeither, ['sku' => 'INV-D']);

        // e) Aucune variante du tout sur la ligne.
        $productNoVariant = $this->makeProduct(['reference' => 'REF-E-'.uniqid()]);

        $order = SalesOrder::create(['reference' => 'CMD-2.6.4-'.uniqid(), 'customer_id' => $customer->id]);

        $itemSizeOnly = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productSizeOnly->id,
            'product_variant_id' => $variantSizeOnly->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);
        $itemColorOnly = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productColorOnly->id,
            'product_variant_id' => $variantColorOnly->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);
        $itemBoth = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productBoth->id,
            'product_variant_id' => $variantBoth->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);
        $itemNeither = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productNeither->id,
            'product_variant_id' => $variantNeither->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);
        $itemNoVariant = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productNoVariant->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);

        $order->markAsConfirmed();
        $order->fresh()->ship([
            $itemSizeOnly->id => 1,
            $itemColorOnly->id => 1,
            $itemBoth->id => 1,
            $itemNeither->id => 1,
            $itemNoVariant->id => 1,
        ]);

        $invoice = Invoice::generateFromSalesOrder($order->fresh());

        $lineFor = fn (int $productId): ?string => $invoice->lines()
            ->where('product_id', $productId)
            ->value('variant_description');

        $this->assertSame('42 / INV-A', $lineFor($productSizeOnly->id));
        $this->assertSame('Rouge / INV-B', $lineFor($productColorOnly->id));
        $this->assertSame('42 / Rouge / INV-C', $lineFor($productBoth->id));
        $this->assertSame('INV-D', $lineFor($productNeither->id));
        $this->assertNull($lineFor($productNoVariant->id));
    }
}
