<?php

namespace Tests\Feature;

use App\Models\CreditNoteSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Étape T24 (point 5) — numérotation des avoirs : strictement unique,
 * sans doublon, protégée par verrouillage transactionnel
 * (CreditNoteSequence::nextNumber()), STRICTEMENT indépendante
 * d'InvoiceSequence (T23, non modifiée).
 */
class CreditNoteNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_numeros_sont_strictement_croissants(): void
    {
        $this->assertSame('AV-2026-000001', CreditNoteSequence::nextNumber(2026, 'AV'));
        $this->assertSame('AV-2026-000002', CreditNoteSequence::nextNumber(2026, 'AV'));
        $this->assertSame('AV-2026-000003', CreditNoteSequence::nextNumber(2026, 'AV'));
    }

    public function test_deux_annees_differentes_ont_des_compteurs_independants(): void
    {
        $this->assertSame('AV-2025-000001', CreditNoteSequence::nextNumber(2025, 'AV'));
        $this->assertSame('AV-2026-000001', CreditNoteSequence::nextNumber(2026, 'AV'));
        $this->assertSame('AV-2025-000002', CreditNoteSequence::nextNumber(2025, 'AV'));
    }

    public function test_le_prefixe_configure_est_respecte(): void
    {
        $this->assertSame('NC-2026-000001', CreditNoteSequence::nextNumber(2026, 'NC'));
    }

    public function test_un_appel_repete_sur_une_annee_deja_initialisee_ne_leve_aucune_exception(): void
    {
        CreditNoteSequence::nextNumber(2026, 'AV');
        $this->assertCount(1, CreditNoteSequence::where('year', 2026)->get());

        $second = CreditNoteSequence::nextNumber(2026, 'AV');

        $this->assertSame('AV-2026-000002', $second);
        $this->assertCount(1, CreditNoteSequence::where('year', 2026)->get());
    }

    /**
     * La séquence des avoirs est physiquement une table séparée de
     * celle des factures (T23) : les compteurs n'interfèrent jamais.
     */
    public function test_la_sequence_des_avoirs_est_independante_de_celle_des_factures(): void
    {
        \App\Models\InvoiceSequence::nextNumber(2026, 'FA');
        \App\Models\InvoiceSequence::nextNumber(2026, 'FA');

        $this->assertSame('AV-2026-000001', CreditNoteSequence::nextNumber(2026, 'AV'));
    }

    /**
     * Correctif de sécurité (post-implémentation, avant commit) — voir
     * le commentaire de tête de CreditNoteSequence.php : preuve que le
     * rollback d'une transaction englobante (le cas réel de
     * CreditNote::generateFromInvoice() en cas d'échec après la
     * réservation du numéro) annule correctement cette réservation —
     * jamais de doublon, jamais de compteur incohérent. Le "trou"
     * potentiel (numéro jamais réutilisé après un échec confirmé APRÈS
     * commit) n'a pas lieu ici puisque tout est annulé ensemble.
     */
    public function test_un_rollback_de_la_transaction_englobante_annule_la_reservation_du_numero(): void
    {
        $first = CreditNoteSequence::nextNumber(2026, 'AV');
        $this->assertSame('AV-2026-000001', $first);

        try {
            DB::transaction(function () {
                // Réserve (en principe) le numéro 000002, puis échoue
                // avant que la transaction englobante ne commit.
                CreditNoteSequence::nextNumber(2026, 'AV');

                throw new \RuntimeException('Échec simulé après réservation du numéro.');
            });

            $this->fail('L\'exception simulée aurait dû se propager.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Échec simulé après réservation du numéro.', $e->getMessage());
        }

        // Le numéro "brûlé" par la tentative annulée redevient
        // disponible : le prochain appel réussi reprend à 000002,
        // jamais un doublon de 000001, jamais un saut à 000003.
        $second = CreditNoteSequence::nextNumber(2026, 'AV');
        $this->assertSame('AV-2026-000002', $second);
    }

    /**
     * Démonstration de concurrence réelle renforcée — 12 processus OS
     * indépendants (au-delà des 10 processus qui avaient précédemment
     * fait apparaître un doublon avec l'ancien mécanisme), chacun
     * générant 6 numéros. Vérifie : aucun doublon, aucune lacune,
     * continuité stricte de la séquence.
     */
    public function test_douze_processus_concurrents_ne_produisent_jamais_de_doublon_ni_de_trou(): void
    {
        $scratchDir = sys_get_temp_dir().'/t24_seq_test_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';
        touch($dbFile);

        // Migre uniquement credit_note_sequences dans cette base isolée.
        // proc_open() (pas exec()) avec un tableau $env explicite : reste
        // valable que le shell sous-jacent soit cmd.exe (Windows) ou un
        // shell POSIX — jamais de syntaxe "VAR=valeur commande" propre à
        // bash, qui échoue silencieusement sous cmd.exe.
        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();

        $migrateProcess = proc_open(
            ['php', 'artisan', 'migrate', '--path=database/migrations/2026_08_25_160000_create_credit_note_sequences_table.php', '--database=sqlite', '--force'],
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
                \$numbers[] = \\App\\Models\\CreditNoteSequence::nextNumber(2026, 'AV');
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
            ->map(fn (string $n): int => (int) str_replace(['AV-2026-', ], '', $n))
            ->sort()
            ->values();

        for ($i = 0; $i < $expectedTotal; $i++) {
            $this->assertSame($i + 1, $sortedInts[$i], "Trou ou désordre détecté dans la séquence à la position {$i}.");
        }
    }
}
