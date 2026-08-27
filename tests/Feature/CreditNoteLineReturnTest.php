<?php

namespace Tests\Feature;

use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CreditNoteLineReturn;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "retour physique" (Option 3b, validée) — couvre :
 * - la règle centrale : avoir financier ≠ retour physique ≠ mouvement
 *   de stock (un avoir sans retour physique déclaré ne modifie jamais
 *   le stock) ;
 * - la distinction vendable/défectueux ;
 * - le plafond de quantité (cumul jamais supérieur à la quantité
 *   créditée) et la concurrence (verrouillage + retry, même mécanisme
 *   que InvoicePayment/T30/T31/D5) ;
 * - le produit/variante obligatoire ;
 * - l'absence d'entrepôt par défaut ;
 * - la non-régression sur les produits supprimés (garde déjà
 *   existante, désormais également couverte par ce nouveau chemin de
 *   création de StockMovement).
 *
 * TVA 0% (même convention que InvoiceCreditNoteReconciliationTest) :
 * total_ttc = total_ht, calcul de référence simple et non ambigu.
 */
class CreditNoteLineReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        TaxRate::create([
            'label' => 'Vente 0%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 0,
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

    /**
     * Facture à 2 lignes : produit A (quantité 5, 100€/unité -> 500€
     * TTC), produit B (quantité 3, 50€/unité -> 150€ TTC). Retourne
     * [Invoice, Product A, Product B].
     */
    private function makeInvoiceWithTwoProducts(): array
    {
        $customer = Customer::create([
            'name' => 'Client Retour',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $productA = Product::create([
            'reference' => 'REF-A-'.uniqid(),
            'nom' => 'Produit A',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 100,
        ]);
        $productB = Product::create([
            'reference' => 'REF-B-'.uniqid(),
            'nom' => 'Produit B',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'L',
            'stock' => 100,
            'prix_achat' => 5,
            'prix_vente' => 50,
        ]);

        $itemA = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productA->id,
            'quantity_ordered' => 5,
            'unit_price' => 100,
        ]);
        $itemB = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $productB->id,
            'quantity_ordered' => 3,
            'unit_price' => 50,
        ]);

        $order->markAsConfirmed();
        $order->fresh()->ship([$itemA->id => 5, $itemB->id => 3]);

        $invoice = Invoice::generateFromSalesOrder($order->fresh());

        return [$invoice, $productA->fresh(), $productB->fresh()];
    }

    /*
     * =================================================================
     * Règle centrale — un avoir sans retour physique ne modifie jamais
     * le stock
     * =================================================================
     */

    public function test_un_avoir_sans_retour_physique_ne_modifie_jamais_le_stock(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $stockABefore = $productA->stock;
        $stockBBefore = $productB->stock;

        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Geste commercial', CreditNote::SETTLEMENT_REFUND);

        $this->assertSame($stockABefore, $productA->fresh()->stock);
        $this->assertSame($stockBBefore, $productB->fresh()->stock);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $this->assertNotNull($creditNote->id);
    }

    /*
     * =================================================================
     * Retour vendable
     * =================================================================
     */

    public function test_retour_total_vendable_reintegre_lintegralite_de_la_quantite(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $stockBefore = $productA->fresh()->stock;

        $return = CreditNoteLineReturn::recordFor($lineA, 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame($stockBefore + 5, $productA->fresh()->stock);
        $this->assertNotNull($return->stockMovement);
        $this->assertSame(5, $return->stockMovement->quantity);
        $this->assertSame('return', $return->stockMovement->type);
    }

    public function test_retour_partiel_vendable_ne_reintegre_que_la_quantite_declaree(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $stockBefore = $productA->fresh()->stock;

        CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame($stockBefore + 2, $productA->fresh()->stock);
    }

    /*
     * =================================================================
     * Retour défectueux
     * =================================================================
     */

    public function test_retour_defectueux_ne_cree_aucun_stock_movement(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineB = $creditNote->lines()->where('product_id', $productB->id)->firstOrFail();
        $stockBefore = $productB->fresh()->stock;

        $return = CreditNoteLineReturn::recordFor($lineB, 3, CreditNoteLineReturn::CONDITION_DEFECTIVE, now()->toDateString());

        $this->assertSame($stockBefore, $productB->fresh()->stock);
        $this->assertNull($return->stockMovement);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $this->assertSame(CreditNoteLineReturn::CONDITION_DEFECTIVE, $return->condition);
    }

    /*
     * =================================================================
     * Avoir partiel + retour partiel
     * =================================================================
     */

    public function test_avoir_partiel_avec_retour_partiel_isole_correctement_les_lignes(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $lineAId = $invoice->lines()->where('product_id', $productA->id)->firstOrFail()->id;

        // Avoir PARTIEL : uniquement la ligne A.
        $creditNote = CreditNote::generateFromInvoice($invoice, [$lineAId], 'Retour partiel', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->firstOrFail();
        $stockABefore = $productA->fresh()->stock;
        $stockBBefore = $productB->fresh()->stock;

        CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame($stockABefore + 2, $productA->fresh()->stock);
        // Produit B jamais crédité, jamais concerné.
        $this->assertSame($stockBBefore, $productB->fresh()->stock);
    }

    /*
     * =================================================================
     * Plusieurs retours successifs
     * =================================================================
     */

    public function test_plusieurs_retours_successifs_sur_la_meme_ligne_se_cumulent(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $stockBefore = $productA->fresh()->stock;

        CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        CreditNoteLineReturn::recordFor($lineA->fresh(), 3, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame($stockBefore + 5, $productA->fresh()->stock);
        $this->assertSame(2, CreditNoteLineReturn::where('credit_note_line_id', $lineA->id)->count());
        $this->assertSame(5, CreditNoteLineReturn::totalReturnedFor($lineA->fresh()));
    }

    /*
     * =================================================================
     * Plusieurs lignes indépendantes
     * =================================================================
     */

    public function test_plusieurs_lignes_dun_avoir_suivent_des_trajectoires_independantes(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $lineB = $creditNote->lines()->where('product_id', $productB->id)->firstOrFail();
        $stockBBefore = $productB->fresh()->stock;

        CreditNoteLineReturn::recordFor($lineA, 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame(0, CreditNoteLineReturn::totalReturnedFor($lineB->fresh()));
        // Produit B jamais touché par le retour sur la ligne A.
        $this->assertSame($stockBBefore, $productB->fresh()->stock);
    }

    /*
     * =================================================================
     * Plusieurs avoirs sur la même facture
     * =================================================================
     */

    public function test_plusieurs_avoirs_sur_la_meme_facture_naffectent_pas_les_memes_lignes(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $lineAId = $invoice->lines()->where('product_id', $productA->id)->firstOrFail()->id;
        $lineBId = $invoice->fresh()->lines()->where('product_id', $productB->id)->firstOrFail()->id;

        $creditNote1 = CreditNote::generateFromInvoice($invoice, [$lineAId], 'Avoir 1', CreditNote::SETTLEMENT_REFUND);
        $creditNote2 = CreditNote::generateFromInvoice($invoice->fresh(), [$lineBId], 'Avoir 2', CreditNote::SETTLEMENT_REFUND);

        $lineA = $creditNote1->lines()->firstOrFail();
        $lineB = $creditNote2->lines()->firstOrFail();
        $stockABefore = $productA->fresh()->stock;
        $stockBBefore = $productB->fresh()->stock;

        CreditNoteLineReturn::recordFor($lineA, 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        CreditNoteLineReturn::recordFor($lineB, 3, CreditNoteLineReturn::CONDITION_DEFECTIVE, now()->toDateString());

        $this->assertSame(5, CreditNoteLineReturn::totalReturnedFor($lineA->fresh()));
        $this->assertSame(3, CreditNoteLineReturn::totalReturnedFor($lineB->fresh()));
        $this->assertSame($stockABefore + 5, $productA->fresh()->stock); // vendable -> +5
        $this->assertSame($stockBBefore, $productB->fresh()->stock); // défectueux -> inchangé
    }

    /*
     * =================================================================
     * Dépassement de quantité / double retour
     * =================================================================
     */

    public function test_depassement_de_la_quantite_retournable_est_rejete(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        CreditNoteLineReturn::recordFor($lineA, 3, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse la quantité');

        CreditNoteLineReturn::recordFor($lineA->fresh(), 3, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
    }

    public function test_double_retour_de_la_meme_quantite_est_rejete(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        CreditNoteLineReturn::recordFor($lineA, 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        $stockAfterFirstReturn = $productA->fresh()->stock;

        $this->expectException(\Exception::class);
        try {
            CreditNoteLineReturn::recordFor($lineA->fresh(), 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        } finally {
            // Aucun effet de bord malgré l'exception : le stock reste
            // strictement celui du premier retour, jamais doublé.
            $this->assertSame($stockAfterFirstReturn, $productA->fresh()->stock);
        }
    }

    /*
     * =================================================================
     * Produit/variante NULL — retour refusé, vendable ET défectueux
     * =================================================================
     */

    public function test_retour_vendable_sans_produit_ni_variante_est_refuse(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        // Simule un produit devenu introuvable (cf. étude validée :
        // scénario déjà quasi impossible via l'application elle-même,
        // reproduit ici directement en base pour le test).
        DB::table('credit_note_lines')->where('id', $lineA->id)->update([
            'product_id' => null,
            'product_variant_id' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('nécessite un produit ou une variante identifiable');

        CreditNoteLineReturn::recordFor($lineA->fresh(), 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame(0, CreditNoteLineReturn::where('credit_note_line_id', $lineA->id)->count());
    }

    public function test_retour_defectueux_sans_produit_ni_variante_est_egalement_refuse(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        DB::table('credit_note_lines')->where('id', $lineA->id)->update([
            'product_id' => null,
            'product_variant_id' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('nécessite un produit ou une variante identifiable');

        CreditNoteLineReturn::recordFor($lineA->fresh(), 1, CreditNoteLineReturn::CONDITION_DEFECTIVE, now()->toDateString());
    }

    /*
     * =================================================================
     * Absence d'entrepôt par défaut
     * =================================================================
     */

    public function test_absence_dentrepot_par_defaut_annule_lensemble_du_retour(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        // Retire le statut "par défaut" sans supprimer l'entrepôt
        // (sa suppression serait de toute façon bloquée : il possède déjà
        // un historique de stock via l'expédition de la commande).
        Warehouse::where('is_default', true)->update(['is_default' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Aucun entrepôt par défaut');

        CreditNoteLineReturn::recordFor($lineA, 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->assertSame(0, CreditNoteLineReturn::where('credit_note_line_id', $lineA->id)->count());
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
    }

    /*
     * =================================================================
     * Rattachement StockMovement <-> CreditNoteLineReturn
     * =================================================================
     */

    public function test_le_stock_movement_genere_est_correctement_rattache_au_retour(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        $return = CreditNoteLineReturn::recordFor($lineA, 4, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $movement = StockMovement::where('credit_note_line_return_id', $return->id)->firstOrFail();
        $this->assertTrue($return->fresh()->stockMovement->is($movement));
        $this->assertTrue($movement->creditNoteLineReturn->is($return));
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
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $return = CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifié');

        $return->update(['quantity' => 999]);
    }

    public function test_un_retour_ne_peut_pas_etre_supprime(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $return = CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimé');

        $return->delete();
    }

    /*
     * =================================================================
     * Non-régression — produits supprimés (garde déjà existante)
     * =================================================================
     */

    public function test_un_produit_ayant_un_retour_vendable_ne_peut_pas_etre_supprime(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        CreditNoteLineReturn::recordFor($lineA, 2, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        $this->expectException(\Exception::class);

        $productA->fresh()->delete();
    }

    /*
     * =================================================================
     * Concurrence — CreditNoteLine.quantity = 5, 3 déjà retournés,
     * deux processus tentent chacun 2 : un seul doit réussir.
     * =================================================================
     */

    public function test_deux_retours_concurrents_dont_le_cumul_depasserait_5_un_seul_est_accepte(): void
    {
        [$scratchDir, $dbFile, $lineId] = $this->prepareIsolatedDatabaseWithCreditNoteLine(quantity: 5, alreadyReturned: 3);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$line = \\App\\Models\\CreditNoteLine::find({$lineId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\CreditNoteLineReturn::recordFor(\$line, 2, 'vendable', now()->toDateString());
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
            "SELECT COALESCE(SUM(quantity), 0) FROM credit_note_line_returns WHERE credit_note_line_id = {$lineId}"
        )->fetchColumn();
        $this->assertSame(5, $totalReturned, 'Le cumul final ne doit jamais dépasser 5.');
    }

    /**
     * Base SQLite isolée et jetable, migrée fraîchement, contenant une
     * unique CreditNoteLine du montant demandé avec un nombre de
     * retours déjà enregistrés — insertion SQL brute pour satisfaire
     * toutes les colonnes NOT NULL des schémas invoices/credit_notes
     * (mêmes conventions que InvoicePaymentTest::prepareIsolatedDatabaseWithInvoiceAndCreditNote()).
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepareIsolatedDatabaseWithCreditNoteLine(int $quantity, int $alreadyReturned): array
    {
        $scratchDir = sys_get_temp_dir().'/return_concurrency_test_'.uniqid();
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

        $pdo->exec("INSERT INTO products (reference, nom, categorie, type, taille, stock, prix_achat, prix_vente, created_at, updated_at) VALUES ('REF-CONC', 'Produit Concurrence', 'Maillots', 'Player Version', 'M', 100, 10, 100, datetime('now'), datetime('now'))");
        $productId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO sales_orders (reference, status, created_at, updated_at) VALUES ('CMD-RET-CONC', 'shipped', datetime('now'), datetime('now'))");
        $salesOrderId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO invoices (
                sales_order_id, number, issued_at, sale_completed_at, operation_category,
                transaction_type, sales_order_reference, customer_type, customer_name,
                seller_legal_name, seller_address, seller_postal_code, seller_city, seller_country,
                seller_siren, vat_regime_snapshot, recovery_indemnity_amount_snapshot,
                total_ht, discount_amount, tax_amount, total_ttc, status, created_at, updated_at
            ) VALUES (
                {$salesOrderId}, 'FA-RET-CONC', date('now'), date('now'), 'vente',
                'b2c_domestic', 'CMD-RET-CONC', 'individual', 'Client Concurrence Retour',
                'Magarrou', '1 rue du Sport', '75000', 'Paris', 'France',
                '111222333', 'standard', 40,
                500, 0, 0, 500, 'issued', datetime('now'), datetime('now')
            )
            SQL);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO invoice_lines (invoice_id, product_id, product_name, quantity, unit_price_ht, subtotal_ht, total_ttc, created_at, updated_at)
            VALUES ({$invoiceId}, {$productId}, 'Produit Concurrence', {$quantity}, 100, 500, 500, datetime('now'), datetime('now'))
            SQL);
        $invoiceLineId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO credit_notes (
                invoice_id, number, issued_at, scope, reason, settlement_type,
                seller_legal_name, seller_address, seller_postal_code, seller_city, seller_country,
                seller_siren, customer_type, customer_name,
                invoice_number_reference, invoice_issued_at_reference, sales_order_reference,
                total_ht, tax_amount, total_ttc, status, created_at, updated_at
            ) VALUES (
                {$invoiceId}, 'AV-RET-CONC', date('now'), 'total', 'Retour concurrence', 'refund',
                'Magarrou', '1 rue du Sport', '75000', 'Paris', 'France',
                '111222333', 'individual', 'Client Concurrence Retour',
                'FA-RET-CONC', date('now'), 'CMD-RET-CONC',
                500, 0, 500, 'issued', datetime('now'), datetime('now')
            )
            SQL);
        $creditNoteId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO credit_note_lines (credit_note_id, invoice_line_id, product_id, product_name, quantity, unit_price_ht, subtotal_ht, total_ttc, created_at, updated_at)
            VALUES ({$creditNoteId}, {$invoiceLineId}, {$productId}, 'Produit Concurrence', {$quantity}, 100, 500, 500, datetime('now'), datetime('now'))
            SQL);
        $lineId = (int) $pdo->lastInsertId();

        if ($alreadyReturned > 0) {
            $pdo->exec(<<<SQL
                INSERT INTO credit_note_line_returns (credit_note_line_id, product_id, quantity, condition, returned_at, created_at, updated_at)
                VALUES ({$lineId}, {$productId}, {$alreadyReturned}, 'vendable', date('now'), datetime('now'), datetime('now'))
                SQL);
        }

        return [$scratchDir, $dbFile, $lineId];
    }

    /*
     * =================================================================
     * Action Filament — "Enregistrer un retour" (ViewCreditNote)
     * =================================================================
     */

    public function test_laction_est_visible_pour_un_manager_quand_une_ligne_est_retournable(): void
    {
        [$invoice] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
            ->assertActionVisible('recordCreditNoteReturn');
    }

    public function test_laction_est_invisible_quand_plus_aucune_ligne_nest_retournable(): void
    {
        [$invoice, $productA, $productB] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $lineB = $creditNote->lines()->where('product_id', $productB->id)->firstOrFail();
        CreditNoteLineReturn::recordFor($lineA, 5, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        CreditNoteLineReturn::recordFor($lineB->fresh(), 3, CreditNoteLineReturn::CONDITION_DEFECTIVE, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->fresh()->getKey()])
            ->assertActionHidden('recordCreditNoteReturn');
    }

    public function test_un_viewer_ne_voit_pas_laction(): void
    {
        [$invoice] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
            ->assertActionHidden('recordCreditNoteReturn');
    }

    public function test_appeler_laction_enregistre_bien_un_retour_vendable(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();
        $stockBefore = $productA->fresh()->stock;

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
            ->callAction('recordCreditNoteReturn', data: [
                'credit_note_line_id' => $lineA->id,
                'quantity' => 3,
                'condition' => CreditNoteLineReturn::CONDITION_SELLABLE,
                'returned_at' => now()->toDateString(),
            ]);

        $this->assertSame(1, CreditNoteLineReturn::where('credit_note_line_id', $lineA->id)->count());
        $this->assertSame($stockBefore + 3, $productA->fresh()->stock);
    }

    /**
     * Étape T25-B — appel direct de mountAction() (pas callAction(), qui
     * pré-vérifie lui-même assertActionVisible() et ne testerait donc
     * jamais le contournement réel) : ->authorize() doit bloquer
     * réellement l'exécution, pas seulement masquer le bouton — même
     * principe que InvoiceCreditNoteActionTest.
     */
    public function test_un_viewer_ne_peut_pas_enregistrer_un_retour_par_appel_direct_de_laction(): void
    {
        [$invoice, $productA] = $this->makeInvoiceWithTwoProducts();
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $lineA = $creditNote->lines()->where('product_id', $productA->id)->firstOrFail();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getKey()])
            ->call('mountAction', 'recordCreditNoteReturn');

        $this->assertSame(0, CreditNoteLineReturn::where('credit_note_line_id', $lineA->id)->count());
    }
}
