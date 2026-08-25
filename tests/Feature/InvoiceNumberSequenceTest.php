<?php

namespace Tests\Feature;

use App\Models\InvoiceSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Étape T23 (D6) — numérotation légale : strictement unique, sans
 * doublon, protégée par verrouillage transactionnel
 * (InvoiceSequence::nextNumber()).
 *
 * Étape T25-A — deux tests supplémentaires ajoutés (rollback + 12
 * processus concurrents), miroir exact des tests équivalents déjà
 * validés sur CreditNoteNumberSequenceTest (T24), qui ont servi de
 * gabarit au correctif de concurrence appliqué à InvoiceSequence.
 */
class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_numeros_sont_strictement_croissants(): void
    {
        $this->assertSame('FA-2026-000001', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2026-000002', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2026-000003', InvoiceSequence::nextNumber(2026, 'FA'));
    }

    public function test_deux_annees_differentes_ont_des_compteurs_independants(): void
    {
        $this->assertSame('FA-2025-000001', InvoiceSequence::nextNumber(2025, 'FA'));
        $this->assertSame('FA-2026-000001', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2025-000002', InvoiceSequence::nextNumber(2025, 'FA'));
        $this->assertSame('FA-2026-000002', InvoiceSequence::nextNumber(2026, 'FA'));
    }

    public function test_le_prefixe_configure_est_respecte(): void
    {
        $this->assertSame('INV-2026-000001', InvoiceSequence::nextNumber(2026, 'INV'));
    }

    /**
     * insertOrIgnore() garantit qu'un second appel sur une année dont
     * la ligne existe déjà ne lève jamais d'exception (contrainte
     * unique sur `year`) — vérifie explicitement ce cas, pas seulement
     * la croissance des numéros.
     */
    public function test_un_appel_repete_sur_une_annee_deja_initialisee_ne_leve_aucune_exception(): void
    {
        InvoiceSequence::nextNumber(2026, 'FA');

        $this->assertCount(1, InvoiceSequence::where('year', 2026)->get());

        $second = InvoiceSequence::nextNumber(2026, 'FA');

        $this->assertSame('FA-2026-000002', $second);
        $this->assertCount(1, InvoiceSequence::where('year', 2026)->get());
    }

    /**
     * Correctif T25-A — preuve que le rollback d'une transaction
     * englobante (le cas réel de Invoice::generateFromSalesOrder() en
     * cas d'échec après la réservation du numéro) annule correctement
     * cette réservation — jamais de doublon, jamais de compteur
     * incohérent.
     */
    public function test_un_rollback_de_la_transaction_englobante_annule_la_reservation_du_numero(): void
    {
        $first = InvoiceSequence::nextNumber(2026, 'FA');
        $this->assertSame('FA-2026-000001', $first);

        try {
            DB::transaction(function () {
                // Réserve (en principe) le numéro 000002, puis échoue
                // avant que la transaction englobante ne commit.
                InvoiceSequence::nextNumber(2026, 'FA');

                throw new \RuntimeException('Échec simulé après réservation du numéro.');
            });

            $this->fail('L\'exception simulée aurait dû se propager.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Échec simulé après réservation du numéro.', $e->getMessage());
        }

        // Le numéro "brûlé" par la tentative annulée redevient
        // disponible : le prochain appel réussi reprend à 000002,
        // jamais un doublon de 000001, jamais un saut à 000003.
        $second = InvoiceSequence::nextNumber(2026, 'FA');
        $this->assertSame('FA-2026-000002', $second);
    }

    /**
     * Démonstration de concurrence réelle — 12 processus OS indépendants
     * (le mécanisme non corrigé avait précédemment produit un doublon
     * dès 10 processus, cf. démonstration ayant motivé le correctif de
     * CreditNoteSequence en T24), chacun générant 6 numéros. Vérifie :
     * aucun doublon, aucune lacune, continuité stricte de la séquence.
     */
    public function test_douze_processus_concurrents_ne_produisent_jamais_de_doublon_ni_de_trou(): void
    {
        $scratchDir = sys_get_temp_dir().'/t25_seq_test_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';
        touch($dbFile);

        // Migre uniquement invoice_sequences dans cette base isolée.
        // proc_open() (pas exec()) avec un tableau $env explicite : reste
        // valable que le shell sous-jacent soit cmd.exe (Windows) ou un
        // shell POSIX — jamais de syntaxe "VAR=valeur commande" propre à
        // bash, qui échoue silencieusement sous cmd.exe.
        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();

        $migrateProcess = proc_open(
            ['php', 'artisan', 'migrate', '--path=database/migrations/2026_08_25_150002_create_invoice_sequences_table.php', '--database=sqlite', '--force'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $basePath,
            $envOverrides,
        );
        $migrateOutput = stream_get_contents($migratePipes[1]).stream_get_contents($migratePipes[2]);
        $migrateStatus = proc_close($migrateProcess);
        $this->assertSame(0, $migrateStatus, 'La migration de la base isolée a échoué : '.$migrateOutput);

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$numbers = [];
            for (\$i = 0; \$i < (int) \$argv[2]; \$i++) {
                \$numbers[] = \\App\\Models\\InvoiceSequence::nextNumber(2026, 'FA');
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, implode("\\n", \$numbers)."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);

        $processCount = 12;
        $perProcess = 6;
        $handles = [];
        $allPipes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                ['php', $probeFile, $resultsFile, (string) $perProcess],
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

        $expectedTotal = $processCount * $perProcess;
        $numbers = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));

        $this->assertCount($expectedTotal, $numbers, 'Nombre de numéros générés inattendu.');

        $unique = array_unique($numbers);
        $this->assertCount($expectedTotal, $unique, 'Doublon détecté parmi les numéros générés : '.implode(', ', array_diff_assoc($numbers, $unique)));

        $sortedInts = collect($numbers)
            ->map(fn (string $n): int => (int) str_replace(['FA-2026-'], '', $n))
            ->sort()
            ->values();

        for ($i = 0; $i < $expectedTotal; $i++) {
            $this->assertSame($i + 1, $sortedInts[$i], "Trou ou désordre détecté dans la séquence à la position {$i}.");
        }
    }
}
