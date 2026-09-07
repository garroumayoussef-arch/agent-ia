<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Filament\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Widgets\LowStockAlertByWarehouse;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\AttributeDefinitionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape 2.6.4 — non-régression du LABEL affiché sur les 12 fichiers
 * Filament du périmètre (cf. checkpoints/steps/2.6.4.json), AVANT toute
 * migration de ces 12 fichiers vers ProductVariant::attributeMirrorValue().
 *
 * Un seul des 12 (StockMovementForm) est migré au moment où ce test est
 * écrit (point 1) : ce test caractérise donc le comportement ACTUEL de
 * l'ensemble des 12 fichiers (déjà migré ou pas encore) et doit rester
 * intégralement vert, à l'identique, après la migration de chacun des
 * 11 fichiers restants — c'est la garantie de non-régression exigée par
 * le manifeste de l'étape ("le texte produit après modification doit
 * être identique à celui produit avant, pour un jeu de variantes
 * représentatif").
 *
 * Chaque test cible le SEUL fichier qu'il documente (nommage explicite),
 * exerce le vrai chemin de code Filament (formulaire/infolist/table/
 * action réellement rendu via Livewire), jamais une réimplémentation de
 * la logique de label — un changement de comportement dans le fichier
 * réel doit faire échouer le test correspondant.
 */
class AttributeMirrorTransverseDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Requis par ProductVariant::attributeMirrorValue() (test 1,
        // déjà migré) : sans AttributeDefinition pour 'size'/'color'/
        // 'version', le miroir écrit par ProductVariant::booted() n'a
        // aucune définition à laquelle se rattacher et
        // attributeMirrorValue() renvoie systématiquement null — même
        // seeder que celui utilisé par AttributeCrossCheckTest.
        $this->seed(AttributeDefinitionSeeder::class);

        // Un seul entrepôt actif : les Select d'entrepôt (warehouse_id)
        // se résolvent alors silencieusement (cf. StockMovementForm/
        // HasPurchaseOrderWorkflowActions/HasSalesOrderWorkflowActions),
        // aucune ambiguïté à gérer dans ce test transverse.
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        // admin : ScopesToOwnWarehouses ne lui applique aucune
        // restriction (cf. Filament/Concerns/ScopesToOwnWarehouses.php)
        // et HasRoleBasedAuthorization l'autorise partout — neutralise
        // toute variable d'autorisation, hors du périmètre de ce test.
        $this->actingAs(User::factory()->create()->assignRole('admin'));
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot 2.6.4',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
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
        ], $attributes));
    }

    /*
     * =================================================================
     * 1. StockMovementForm.php — DÉJÀ migré (point 1) :
     *    attributeMirrorValue('size'|'color'|'version'), puis
     *    " — SKU : {sku}" et toujours " — Stock : {stock}".
     * =================================================================
     */
    public function test_stock_movement_form_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F1',
            'size' => '42',
            'color' => 'Rouge',
            'version' => 'Home',
            'stock' => 7,
        ]);

        // Select rendu par un picker Alpine/JSON (->searchable()) plutôt
        // qu'un <select> HTML natif : on lit directement l'option résolue
        // (tableau PHP) via l'API officielle de test Filament, jamais
        // le HTML échappé, pour ne dépendre d'aucun format d'échappement.
        Livewire::test(CreateStockMovement::class)
            ->fillForm(['product_id' => $product->id])
            ->assertFormFieldExists('product_variant_id', function ($field) use ($variant): bool {
                return ($field->getOptions()[$variant->id] ?? null)
                    === 'Taille : 42 / Couleur : Rouge / Version : Home — SKU : VAR-F1 — Stock : 7';
            });
    }

    /*
     * =================================================================
     * 2. StockMovementInfolist.php — pas encore migré : parts
     *    filtrées (Taille/Couleur/Version), fallback SKU si aucune,
     *    JAMAIS de suffixe SKU/Stock (différent de StockMovementForm).
     * =================================================================
     */
    public function test_stock_movement_infolist_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F2',
            'size' => '42',
            'color' => 'Rouge',
            'version' => 'Home',
            'stock' => 3,
        ]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 3,
        ]);

        Livewire::test(ViewStockMovement::class, ['record' => $movement->getKey()])
            ->assertSee('Taille : 42 / Couleur : Rouge / Version : Home');
    }

    /*
     * =================================================================
     * 3. HasStockTransferAction.php — pas encore migré : Taille/Couleur
     *    uniquement (pas de Version ici), puis " — SKU : {sku}".
     * =================================================================
     */
    public function test_stock_transfer_action_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F3',
            'size' => '42',
            'color' => 'Rouge',
            'stock' => 5,
        ]);

        Livewire::test(ListStockMovements::class)
            ->mountAction('transfer')
            ->fillForm(['product_id' => $product->id])
            ->assertFormFieldExists('product_variant_id', function ($field) use ($variant): bool {
                return ($field->getOptions()[$variant->id] ?? null)
                    === 'Taille : 42 / Couleur : Rouge — SKU : VAR-F3';
            });
    }

    /*
     * =================================================================
     * 4. SalesOrderForm.php — pas encore migré : Taille/Couleur/SKU
     *    (comme des parts égales, pas de suffixe séparé) puis fallback
     *    brut sur le sku si aucune part.
     * =================================================================
     */
    public function test_sales_order_form_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F4',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        Livewire::test(CreateSalesOrder::class)
            ->fillForm(['items' => [['product_id' => $product->id]]])
            ->assertFormFieldExists('items.0.product_variant_id', function ($field) use ($variant): bool {
                return ($field->getOptions()[$variant->id] ?? null)
                    === 'Taille : 42 / Couleur : Rouge / SKU : VAR-F4';
            });
    }

    /*
     * =================================================================
     * 5. SalesOrderInfolist.php — pas encore migré : valeurs BRUTES
     *    (size/color), sans préfixe "Taille :"/"Couleur :", fallback sku.
     * =================================================================
     */
    public function test_sales_order_infolist_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F5',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        $order = SalesOrder::create(['reference' => 'CMD-F5-'.uniqid()]);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 4,
        ]);

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertSee('42 / Rouge');
    }

    /*
     * =================================================================
     * 6. HasSalesOrderWorkflowActions.php (shipOrderAction) — pas
     *    encore migré : "{produit} ({Taille brute}/{Couleur brute}) —
     *    restant à expédier : {n}".
     * =================================================================
     */
    public function test_ship_order_action_label_ligne(): void
    {
        $product = $this->makeProduct(['nom' => 'Maillot F6']);
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F6',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        $order = SalesOrder::create(['reference' => 'CMD-F6-'.uniqid()]);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsConfirmed();

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->mountAction('shipOrder')
            ->assertMountedActionModalSee('Maillot F6 (42 / Rouge) — restant à expédier : 10');
    }

    /*
     * =================================================================
     * 7. PurchaseOrderForm.php — pas encore migré : structure identique
     *    à SalesOrderForm.
     * =================================================================
     */
    public function test_purchase_order_form_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F7',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm(['items' => [['product_id' => $product->id]]])
            ->assertFormFieldExists('items.0.product_variant_id', function ($field) use ($variant): bool {
                return ($field->getOptions()[$variant->id] ?? null)
                    === 'Taille : 42 / Couleur : Rouge / SKU : VAR-F7';
            });
    }

    /*
     * =================================================================
     * 8. PurchaseOrderInfolist.php — pas encore migré : structure
     *    identique à SalesOrderInfolist (valeurs brutes).
     * =================================================================
     */
    public function test_purchase_order_infolist_label_variante(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F8',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-F8-'.uniqid()]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 4,
        ]);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->assertSee('42 / Rouge');
    }

    /*
     * =================================================================
     * 9. HasPurchaseOrderWorkflowActions.php (receiveOrderAction) — pas
     *    encore migré : structure identique à shipOrderAction ("restant
     *    à recevoir" au lieu de "restant à expédier").
     * =================================================================
     */
    public function test_receive_order_action_label_ligne(): void
    {
        $product = $this->makeProduct(['nom' => 'Maillot F9']);
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F9',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-F9-'.uniqid()]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 8,
        ]);
        $order->markAsOrdered();

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->mountAction('receiveOrder')
            ->assertMountedActionModalSee('Maillot F9 (42 / Rouge) — restant à recevoir : 8');
    }

    /*
     * =================================================================
     * 10. HasPurchaseOrderReturnAction.php (returnItemLabel, options du
     *     Select purchase_order_item_id) — pas encore migré :
     *     "{produit} ({Taille brute}/{Couleur brute}) —
     *     {déjà retourné}/{reçu} déjà retourné(s)".
     * =================================================================
     */
    public function test_purchase_order_return_action_label_ligne(): void
    {
        $product = $this->makeProduct(['nom' => 'Maillot F10']);
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F10',
            'size' => '42',
            'color' => 'Rouge',
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-F10-'.uniqid()]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();
        $order->fresh()->receive([$item->id => 5]);

        // Select sans ->searchable()/->preload() : rendu comme <select>
        // HTML natif (texte brut, non échappé) — contrairement aux
        // autres Select de ce fichier, jamais de jsonFragment() ici.
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->fresh()->getKey()])
            ->mountAction('recordPurchaseOrderReturn')
            ->assertMountedActionModalSee('Maillot F10 (42 / Rouge) — 0/5 déjà retourné(s)');
    }

    /*
     * =================================================================
     * 11. WarehouseStocksTable.php — pas encore migré : valeurs brutes
     *     (size/color) puis " — SKU : {sku}" si présent.
     * =================================================================
     */
    public function test_warehouse_stocks_table_label_variante(): void
    {
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F11',
            'size' => '42',
            'color' => 'Rouge',
            'stock' => 6,
        ]);

        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 6,
        ]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertSee('42 / Rouge — SKU : VAR-F11');
    }

    /*
     * =================================================================
     * 12. LowStockAlertByWarehouse.php — pas encore migré : même format
     *     que WarehouseStocksTable. Widget testé directement (sans
     *     Livewire::test, cf. LowStockAlertByWarehouseTest.php déjà
     *     existant qui suit la même convention) : on récupère la
     *     colonne réelle du Table et on invoque son VRAI
     *     formatStateUsing() via Column::record()/formatState(), sans
     *     jamais réimplémenter la logique de label.
     * =================================================================
     */
    public function test_low_stock_alert_by_warehouse_label_variante(): void
    {
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, [
            'sku' => 'VAR-F12',
            'size' => '42',
            'color' => 'Rouge',
            'stock' => 1,
        ]);

        $line = WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 1,
        ]);

        $widget = new LowStockAlertByWarehouse();
        $table = $widget->table(Table::make($widget));
        $column = $table->getColumn('productVariant.sku');
        $column->record($line);

        $this->assertSame(
            '42 / Rouge — SKU : VAR-F12',
            $column->formatState($line->productVariant?->sku)
        );
    }
}
