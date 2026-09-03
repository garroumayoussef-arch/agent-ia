<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Systeme de checkpoint - operations DB (backup/verify/restore), 100% PDO.
 *
 * Aucune dependance a un outil externe (sqlite3, mysqldump) : le backup
 * utilise VACUUM INTO (snapshot atomique et coherent) et la verification
 * utilise PRAGMA integrity_check, tous deux via PDO/pdo_sqlite uniquement.
 * Voir checkpoints/README.md, section "Backup/restore DB".
 *
 * Ne fait pas partie de la logique metier de l'application : commande
 * additive, sans effet tant qu'elle n'est pas invoquee explicitement avec
 * une action et sans jamais toucher la connexion configuree si --file/
 * --target sont fournis explicitement.
 */
class CheckpointDb extends Command
{
    protected $signature = 'checkpoint:db {action : backup|verify|restore} {--file=} {--target=} {--step=}';

    protected $description = 'Systeme de checkpoint : backup/verify/restore de la base SQLite via PDO uniquement (aucun outil externe requis).';

    public function handle(): int
    {
        $action = $this->argument('action');
        $defaultDb = config('database.connections.' . config('database.default') . '.database');

        try {
            switch ($action) {
                case 'backup':
                    $source = $this->option('file') ?: $defaultDb;
                    $step = $this->option('step');
                    $suffix = $step ? '-' . $step : '';
                    $target = $this->option('target') ?: base_path('checkpoints/backups/' . date('Ymd-His') . $suffix . '.sqlite');
                    $this->backup($source, $target);
                    $this->line(json_encode(['ok' => true, 'action' => 'backup', 'target' => $target]));
                    return self::SUCCESS;

                case 'verify':
                    $file = $this->option('file');
                    if (!$file) {
                        $this->error('--file est requis pour verify');
                        return self::FAILURE;
                    }
                    $result = $this->verify($file);
                    $this->line(json_encode(array_merge($result, ['action' => 'verify'])));
                    return $result['ok'] ? self::SUCCESS : self::FAILURE;

                case 'restore':
                    $backup = $this->option('file');
                    $target = $this->option('target') ?: $defaultDb;
                    if (!$backup) {
                        $this->error('--file (backup source) est requis pour restore');
                        return self::FAILURE;
                    }
                    $this->restore($backup, $target);
                    $this->line(json_encode(['ok' => true, 'action' => 'restore', 'target' => $target]));
                    return self::SUCCESS;

                default:
                    $this->error("Action inconnue: {$action}");
                    return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->line(json_encode(['ok' => false, 'action' => $action, 'reason' => $e->getMessage()]));
            return self::FAILURE;
        }
    }

    /**
     * Snapshot atomique et coherent, y compris en presence d'ecritures WAL,
     * via VACUUM INTO (PDO/sqlite uniquement, aucun binaire externe).
     */
    private function backup(string $sourcePath, string $backupPath): void
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException("Base source introuvable: {$sourcePath}");
        }
        $dir = dirname($backupPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (is_file($backupPath)) {
            unlink($backupPath);
        }
        $pdo = new \PDO('sqlite:' . $sourcePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $escaped = str_replace("'", "''", $backupPath);
        $pdo->exec("VACUUM INTO '{$escaped}'");
    }

    /** PRAGMA integrity_check + comptage de lignes par table. */
    private function verify(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => "Fichier introuvable: {$path}"];
        }
        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                return ['ok' => false, 'reason' => "integrity_check: {$integrity}"];
            }
            $counts = [];
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            }
            return ['ok' => true, 'counts' => $counts];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Restauration controlee : verifie le backup, sauvegarde la cible existante, puis copie. */
    private function restore(string $backupPath, string $targetPath): void
    {
        if (!is_file($backupPath)) {
            throw new \RuntimeException("Backup introuvable: {$backupPath}");
        }
        $verify = $this->verify($backupPath);
        if (!$verify['ok']) {
            throw new \RuntimeException('Backup non exploitable, restauration annulee: ' . $verify['reason']);
        }
        if (is_file($targetPath)) {
            $safety = $targetPath . '.pre-restore-' . date('Ymd-His') . '.sqlite';
            copy($targetPath, $safety);
        }
        copy($backupPath, $targetPath);
    }
}
