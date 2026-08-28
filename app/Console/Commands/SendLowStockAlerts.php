<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Notifications\LowStockAlertDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Chantier "Notifications & communication" V1 (D1/D2/D3/D6, validés) —
 * digest quotidien de stock bas, un par admin/manager, scopé par
 * entrepôt pour un manager (même règle que
 * LowStockAlertByWarehouse/ScopesToOwnWarehouses, reproduite ICI
 * localement — cette commande CLI n'a AUCUN utilisateur authentifié
 * courant au sens de Auth::user() : ScopesToOwnWarehouses (structurellement
 * lié à Auth::user()) n'est donc jamais réutilisable tel quel dans un
 * contexte qui doit itérer sur CHAQUE utilisateur l'un après l'autre —
 * le trait lui-même n'est jamais modifié).
 *
 * D8 (validé) — au plus un digest par utilisateur et par jour civil
 * (occurrence_key = date du jour), via NotificationLog::reserve().
 *
 * Périmètre V1 (validé) — canal interne UNIQUEMENT (cloche Filament,
 * notification `database`), jamais un email pour cet événement.
 */
class SendLowStockAlerts extends Command
{
    protected $signature = 'notifications:low-stock-alerts';

    protected $description = "Envoie un digest quotidien des alertes de stock bas à chaque admin/manager (scopé par entrepôt pour un manager).";

    public function handle(): int
    {
        $today = now()->toDateString();
        $sentCount = 0;

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['admin', 'manager']))
            ->get()
            ->each(function (User $user) use ($today, &$sentCount) {
                $lowStockLines = $this->lowStockLinesFor($user);

                if ($lowStockLines->isEmpty()) {
                    return;
                }

                // D8 (validé) — réservation AVANT tout envoi, jamais
                // après : un digest déjà notifié aujourd'hui pour cet
                // utilisateur retourne null ici, aucun second envoi.
                $log = NotificationLog::reserve(
                    $user,
                    'low_stock_digest',
                    'database',
                    $user->email,
                    occurrenceKey: $today,
                );

                if ($log === null || $log->status !== NotificationLog::STATUS_QUEUED) {
                    return;
                }

                // Canal `database` natif Laravel : écriture synchrone
                // immédiate (pas de mise en file, pas de dépendance au
                // worker de queue — contrairement aux 4 emails externes).
                $user->notify(new LowStockAlertDigestNotification($lowStockLines));
                $log->markAsSent();
                $sentCount++;
            });

        $this->info("Digests de stock bas envoyés : {$sentCount}.");

        return self::SUCCESS;
    }

    /**
     * Même règle que ScopesToOwnWarehouses::resolveScopedWarehouseIds()
     * (T19/T21, jamais modifié), reproduite ici pour un utilisateur
     * ARBITRAIRE passé en paramètre (jamais Auth::user()) : admin =
     * aucune restriction ; manager = ses entrepôts attribués uniquement,
     * vide si aucun (fail-closed, comme partout ailleurs dans ce
     * projet) — aucun autre rôle n'atteint cette méthode (filtré en
     * amont par la requête whereHas('roles', ...) de handle()).
     *
     * Même seuil que le reste du projet (Product::LOW_STOCK_THRESHOLD)
     * et même filtre d'entrepôt actif que LowStockAlertByWarehouse,
     * jamais un second seuil ou une seconde règle inventée ici.
     *
     * @return Collection<int, WarehouseStock>
     */
    private function lowStockLinesFor(User $user): Collection
    {
        $query = WarehouseStock::query()
            ->where('stock', '<=', Product::LOW_STOCK_THRESHOLD)
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true));

        if (! $user->hasRole('admin')) {
            $warehouseIds = $user->warehouses()->pluck('warehouses.id');

            if ($warehouseIds->isEmpty()) {
                return collect();
            }

            $query->whereIn('warehouse_id', $warehouseIds);
        }

        return $query->with(['product', 'productVariant', 'warehouse'])->get();
    }
}
