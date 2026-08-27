<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemReturn;
use App\Models\StockMovement;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "retour physique fournisseur" (décisions 1 à 6, validées) —
 * couvre :
 * - la règle centrale (décision 1) : un retour physique est totalement
 *   indépendant de SupplierCreditNote/SupplierInvoice (aucun lien,
 *   aucune donnée dérivée) ;
 * - le rattachement à PurchaseOrderItem (décision 2), plafonné à
 *   quantity_received (jamais quantity_ordered) ;
 * - l'absence de distinction de condition (décision 3) : TOUJOURS un
 *   StockMovement, systématiquement décrémenté ;
 * - l'absence de contrainte liée au statut du bon de commande
 *   (décision 4) ;
 * - la sélection d'entrepôt (décision 5), même mécanisme que
 *   PurchaseOrder::receive() ;
 * - la concurrence (verrouillage + retry, même mécanisme que
 *   CreditNoteLineReturn) ;
 * - la non-régression sur les produits supprimés (garde déjà
 *   existante, désormais également couverte par ce nouveau chemin de
 *   création de StockMovement).
 */
class PurchaseOrderItemReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Retour Fournisseur',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    /**
     * Bon de commande intégralement réceptionné, à 2 lignes : produit A
     * (5 reçues), produit B (3 reçues). Retourne [PurchaseOrder, itemA,
     * itemB, productA, productB].
     */
    private function makeReceivedOrderWithTwoProducts(): array
    {
        $productA = $this->makeProduct(['reference' => 'REF-A-'.uniqid(), 'nom' => 'Produit A']);
        $productB = $this->makeProduct(['reference' => 'REF-B-'.uniqid(), 'nom' => 'Produit B']);

        $order = PurchaseOrder::create(['reference' => 'BC-'.uniqid()]);

        $itemA = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $itemB = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity_ordered' => 3,
            'unit_price' => 5,
        ]);

        $order->markAsOrdered();
        $order->fresh()->receive([$itemA->id => 5, $itemB->id => 3]);

        return [$order->fresh(), $itemA->fresh(), $itemB->fresh(), $productA->fresh(), $productB->fresh()];
    }

    /*
     * =================================================================
     * Décision 1 (validée) — indépendance totale de SupplierCreditNote/
     * SupplierInvoice
     * =================================================================
     */

    public function test_un_retour_physique_est_enregistrable_sans_aucune_facture_ni_avoir_fournisseur(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();
        $this->assertSame(0, SupplierInvoice::count());
        $this->assertSame(0, SupplierCreditNote::count());

        $return = PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());

        $this->assertNotNull($return->id);
        $this->assertSame(3, $productA->fresh()->stock); // 5 - 2
        // Aucune écriture, ni requise ni générée, côté avoir fournisseur.
        $this->assertSame(0, SupplierInvoice::count());
        $this->assertSame(0, SupplierCreditNote::count());
    }

    /*
     * =================================================================
     * Retour total / partiel
     * =================================================================
     */

    public function test_retour_total_decremente_lintegralite_de_la_quantite(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        $return = PurchaseOrderItemReturn::recordFor($itemA, 5, now()->toDateString());

        $this->assertSame(0, $productA->fresh()->stock); // 5 - 5
        $this->assertNotNull($return->stockMovement);
        $this->assertSame(5, $return->stockMovement->quantity);
        $this->assertSame('return_to_supplier', $return->stockMovement->type);
    }

    public function test_retour_partiel_ne_decremente_que_la_quantite_declaree(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());

        $this->assertSame(3, $productA->fresh()->stock); // 5 - 2
    }

    /*
     * =================================================================
     * Plusieurs retours successifs / lignes / commandes indépendantes
     * =================================================================
     */

    public function test_plusieurs_retours_successifs_sur_la_meme_ligne_se_cumulent(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());
        PurchaseOrderItemReturn::recordFor($itemA->fresh(), 3, now()->toDateString());

        $this->assertSame(0, $productA->fresh()->stock); // 5 - 2 - 3
        $this->assertSame(2, PurchaseOrderItemReturn::where('purchase_order_item_id', $itemA->id)->count());
        $this->assertSame(5, PurchaseOrderItemReturn::totalReturnedFor($itemA->fresh()));
    }

    public function test_plusieurs_lignes_dune_commande_suivent_des_trajectoires_independantes(): void
    {
        [, $itemA, $itemB, , $productB] = $this->makeReceivedOrderWithTwoProducts();
        $stockBBefore = $productB->fresh()->stock;

        PurchaseOrderItemReturn::recordFor($itemA, 5, now()->toDateString());

        $this->assertSame(0, PurchaseOrderItemReturn::totalReturnedFor($itemB->fresh()));
        $this->assertSame($stockBBefore, $productB->fresh()->stock);
    }

    public function test_plusieurs_commandes_naffectent_pas_les_memes_lignes(): void
    {
        [, $itemA1] = $this->makeReceivedOrderWithTwoProducts();
        [, $itemA2] = $this->makeReceivedOrderWithTwoProducts();

        PurchaseOrderItemReturn::recordFor($itemA1, 5, now()->toDateString());

        $this->assertSame(5, PurchaseOrderItemReturn::totalReturnedFor($itemA1->fresh()));
        $this->assertSame(0, PurchaseOrderItemReturn::totalReturnedFor($itemA2->fresh()));
    }

    /*
     * =================================================================
     * Dépassement de quantité / double retour
     * =================================================================
     */

    public function test_depassement_de_la_quantite_retournable_est_rejete(): void
    {
        [, $itemA] = $this->makeReceivedOrderWithTwoProducts();

        PurchaseOrderItemReturn::recordFor($itemA, 3, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse la quantité');

        PurchaseOrderItemReturn::recordFor($itemA->fresh(), 3, now()->toDateString());
    }

    public function test_double_retour_de_la_meme_quantite_est_rejete(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        PurchaseOrderItemReturn::recordFor($itemA, 5, now()->toDateString());
        $stockAfterFirstReturn = $productA->fresh()->stock;

        $this->expectException(\Exception::class);
        try {
            PurchaseOrderItemReturn::recordFor($itemA->fresh(), 5, now()->toDateString());
        } finally {
            // Aucun effet de bord malgré l'exception : le stock reste
            // strictement celui du premier retour, jamais doublé.
            $this->assertSame($stockAfterFirstReturn, $productA->fresh()->stock);
        }
    }

    public function test_un_retour_sur_une_ligne_jamais_receptionnee_est_rejete(): void
    {
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-'.uniqid()]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        // Jamais réceptionnée : quantity_received = 0.

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse la quantité');

        PurchaseOrderItemReturn::recordFor($item, 1, now()->toDateString());
    }

    /*
     * =================================================================
     * Stock insuffisant au moment du retour
     * =================================================================
     */

    public function test_stock_insuffisant_lors_du_retour_est_rejete(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        // Le stock a diminué depuis la réception (ex. vente entre-temps) :
        // la ligne reste "retournable" du point de vue de quantity_received,
        // mais le stock RÉEL est désormais insuffisant pour honorer 5.
        StockMovement::create([
            'product_id' => $productA->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);
        $this->assertSame(1, $productA->fresh()->stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stock insuffisant');

        PurchaseOrderItemReturn::recordFor($itemA->fresh(), 5, now()->toDateString());
    }

    /*
     * =================================================================
     * Entrepôt (décision 5)
     * =================================================================
     */

    public function test_absence_dentrepot_par_defaut_annule_lensemble_du_retour(): void
    {
        [, $itemA] = $this->makeReceivedOrderWithTwoProducts();

        Warehouse::where('is_default', true)->update(['is_default' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Aucun entrepôt par défaut');

        PurchaseOrderItemReturn::recordFor($itemA, 1, now()->toDateString());

        $this->assertSame(0, PurchaseOrderItemReturn::where('purchase_order_item_id', $itemA->id)->count());
        $this->assertSame(0, StockMovement::where('type', 'return_to_supplier')->count());
    }

    public function test_plusieurs_entrepots_actifs_exigent_une_selection_explicite(): void
    {
        [, $itemA] = $this->makeReceivedOrderWithTwoProducts();

        Warehouse::create(['name' => 'Second entrepôt', 'code' => 'second', 'is_default' => false, 'is_active' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Plusieurs entrepôts sont disponibles');

        PurchaseOrderItemReturn::recordFor($itemA, 1, now()->toDateString());
    }

    public function test_un_entrepot_explicitement_selectionne_est_accepte_meme_avec_plusieurs_actifs(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        $second = Warehouse::create(['name' => 'Second entrepôt', 'code' => 'second', 'is_default' => false, 'is_active' => true]);

        $return = PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString(), $second->id);

        $this->assertSame($second->id, $return->stockMovement->warehouse_id);
        $this->assertSame(3, $productA->fresh()->stock);
    }

    /*
     * =================================================================
     * Rattachement StockMovement <-> PurchaseOrderItemReturn
     * =================================================================
     */

    public function test_le_stock_movement_genere_est_correctement_rattache_au_retour(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();

        $return = PurchaseOrderItemReturn::recordFor($itemA, 4, now()->toDateString());

        $movement = StockMovement::where('purchase_order_item_return_id', $return->id)->firstOrFail();
        $this->assertTrue($return->fresh()->stockMovement->is($movement));
        $this->assertTrue($movement->purchaseOrderItemReturn->is($return));
        $this->assertSame($productA->id, $movement->product_id);
        $this->assertSame(4, $movement->quantity);
    }

    /*
     * =================================================================
     * Immuabilité
     * =================================================================
     */

    public function test_un_retour_ne_peut_pas_etre_modifie(): void
    {
        [, $itemA] = $this->makeReceivedOrderWithTwoProducts();
        $return = PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifié');

        $return->update(['quantity' => 999]);
    }

    public function test_un_retour_ne_peut_pas_etre_supprime(): void
    {
        [, $itemA] = $this->makeReceivedOrderWithTwoProducts();
        $return = PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimé');

        $return->delete();
    }

    /*
     * =================================================================
     * Non-régression — produits supprimés (garde déjà existante)
     * =================================================================
     */

    public function test_un_produit_ayant_un_retour_fournisseur_ne_peut_pas_etre_supprime(): void
    {
        [, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();
        PurchaseOrderItemReturn::recordFor($itemA, 2, now()->toDateString());

        $this->expectException(\Exception::class);

        $productA->fresh()->delete();
    }

    /*
     * =================================================================
     * Concurrence — quantity_received = 5, 3 déjà retournés, deux
     * processus tentent chacun 2 : un seul doit réussir.
     * =================================================================
     */

    public function test_deux_retours_concurrents_dont_le_cumul_depasserait_5_un_seul_est_accepte(): void
    {
        [$scratchDir, $dbFile, $itemId] = $this->prepareIsolatedDatabaseWithPurchaseOrderItem(quantityReceived: 5, alreadyReturned: 3);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$item = \\App\\Models\\PurchaseOrderItem::find({$itemId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\PurchaseOrderItemReturn::recordFor(\$item, 2, now()->toDateString());
                \$result = 'ACCEPTED';
            } catch (\\Throwable \$e) {
                \$result = 'REJECTED';
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, \$result."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);

        $handles = [];
        $allPipes = [];
        for ($i = 0; $i < 2; $i++) {
            $handles[] = proc_open(
                ['php', $probeFile, $resultsFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $envOverrides,
            );
            $allPipes[$i] = $pipes;
        }
        foreach ($handles as $i => $handle) {
            stream_get_contents($allPipes[$i][1]);
            stream_get_contents($allPipes[$i][2]);
            proc_close($handle);
        }

        $results = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));
        $accepted = count(array_filter($results, fn ($r) => $r === 'ACCEPTED'));
        $rejected = count(array_filter($results, fn ($r) => $r === 'REJECTED'));

        $this->assertSame(1, $accepted, 'Exactement un des deux retours concurrents doit être accepté.');
        $this->assertSame(1, $rejected, "L'autre doit être rejeté (dépassement de la quantité retournable).");

        $pdo = new \PDO('sqlite:'.$dbFile);
        $totalReturned = (int) $pdo->query(
            "SELECT COALESCE(SUM(quantity), 0) FROM purchase_order_item_returns WHERE purchase_order_item_id = {$itemId}"
        )->fetchColumn();
        $this->assertSame(5, $totalReturned, 'Le cumul final ne doit jamais dépasser 5.');
    }

    /**
     * Base SQLite isolée et jetable, migrée fraîchement, contenant une
     * unique PurchaseOrderItem réceptionnée à hauteur de $quantityReceived,
     * avec un nombre de retours déjà enregistrés — insertion SQL brute,
     * mêmes conventions que
     * CreditNoteLineReturnTest::prepareIsolatedDatabaseWithCreditNoteLine().
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepareIsolatedDatabaseWithPurchaseOrderItem(int $quantityReceived, int $alreadyReturned): array
    {
        $scratchDir = sys_get_temp_dir().'/supplier_return_concurrency_test_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        touch($dbFile);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();

        $migrateProcess = proc_open(
            ['php', 'artisan', 'migrate', '--database=sqlite', '--force'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $basePath,
            $envOverrides,
        );
        $migrateOutput = stream_get_contents($migratePipes[1]).stream_get_contents($migratePipes[2]);
        $migrateStatus = proc_close($migrateProcess);
        $this->assertSame(0, $migrateStatus, 'La migration de la base isolée a échoué : '.$migrateOutput);

        $pdo = new \PDO('sqlite:'.$dbFile);

        $pdo->exec("INSERT INTO warehouses (name, code, is_default, created_at, updated_at) VALUES ('Entrepôt', 'defaut', 1, datetime('now'), datetime('now'))");

        $pdo->exec("INSERT INTO products (reference, nom, categorie, type, taille, stock, prix_achat, prix_vente, created_at, updated_at) VALUES ('REF-CONC-FRS', 'Produit Concurrence Fournisseur', 'Maillots', 'Player Version', 'M', 100, 10, 100, datetime('now'), datetime('now'))");
        $productId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO purchase_orders (reference, status, created_at, updated_at) VALUES ('BC-RET-CONC', 'received', datetime('now'), datetime('now'))");
        $orderId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, quantity_received, created_at, updated_at)
            VALUES ({$orderId}, {$productId}, {$quantityReceived}, {$quantityReceived}, datetime('now'), datetime('now'))
            SQL);
        $itemId = (int) $pdo->lastInsertId();

        if ($alreadyReturned > 0) {
            $pdo->exec(<<<SQL
                INSERT INTO purchase_order_item_returns (purchase_order_item_id, product_id, quantity, returned_at, created_at, updated_at)
                VALUES ({$itemId}, {$productId}, {$alreadyReturned}, date('now'), datetime('now'), datetime('now'))
                SQL);
        }

        return [$scratchDir, $dbFile, $itemId];
    }

    /*
     * =================================================================
     * Action Filament — "Déclarer un retour fournisseur" (ViewPurchaseOrder)
     * =================================================================
     */

    public function test_laction_est_visible_pour_un_manager_quand_une_ligne_est_retournable(): void
    {
        [$order] = $this->makeReceivedOrderWithTwoProducts();

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('recordPurchaseOrderReturn');
    }

    public function test_laction_est_invisible_quand_plus_aucune_ligne_nest_retournable(): void
    {
        [$order, $itemA, $itemB] = $this->makeReceivedOrderWithTwoProducts();
        PurchaseOrderItemReturn::recordFor($itemA, 5, now()->toDateString());
        PurchaseOrderItemReturn::recordFor($itemB->fresh(), 3, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('recordPurchaseOrderReturn');
    }

    public function test_un_viewer_ne_voit_pas_laction(): void
    {
        [$order] = $this->makeReceivedOrderWithTwoProducts();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('recordPurchaseOrderReturn');
    }

    public function test_appeler_laction_enregistre_bien_un_retour(): void
    {
        [$order, $itemA, , $productA] = $this->makeReceivedOrderWithTwoProducts();
        $stockBefore = $productA->fresh()->stock;
        $defaultWarehouseId = Warehouse::where('is_default', true)->value('id');

        // Étape T19 — un manager n'est autorisé à opérer que sur les
        // entrepôts qui lui sont explicitement attribués (même
        // convention que PurchaseOrderResourceTest::receiveOrder).
        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach($defaultWarehouseId);
        $this->actingAs($manager);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->callAction('recordPurchaseOrderReturn', data: [
                'purchase_order_item_id' => $itemA->id,
                'quantity' => 3,
                'warehouse_id' => $defaultWarehouseId,
                'returned_at' => now()->toDateString(),
            ]);

        $this->assertSame(1, PurchaseOrderItemReturn::where('purchase_order_item_id', $itemA->id)->count());
        $this->assertSame($stockBefore - 3, $productA->fresh()->stock);
    }

    /**
     * Même principe que CreditNoteLineReturnTest (T25-B) : appel direct
     * de mountAction() (pas callAction(), qui pré-vérifie lui-même
     * assertActionVisible()) pour reproduire un contournement réel du
     * bouton masqué.
     */
    public function test_un_viewer_ne_peut_pas_enregistrer_un_retour_par_appel_direct_de_laction(): void
    {
        [$order, $itemA] = $this->makeReceivedOrderWithTwoProducts();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'recordPurchaseOrderReturn');

        $this->assertSame(0, PurchaseOrderItemReturn::where('purchase_order_item_id', $itemA->id)->count());
    }
}
