<?php

namespace App\Notifications;

use App\Models\WarehouseStock;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Chantier "Notifications & communication" V1 (D1/D3/D9, validés) —
 * notification interne (canal `database` natif de Laravel, jamais un
 * mécanisme maison), UN digest par jour et par utilisateur (anti-dup
 * gérée en amont par SendLowStockAlerts via NotificationLog — cette
 * classe ne fait que porter le contenu affiché dans la cloche du
 * panel Filament).
 */
class LowStockAlertDigestNotification extends Notification
{
    /**
     * @param  Collection<int, WarehouseStock>  $lowStockLines
     */
    public function __construct(private readonly Collection $lowStockLines)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Alertes stock bas',
            'count' => $this->lowStockLines->count(),
            'lines' => $this->lowStockLines->map(fn (WarehouseStock $line): array => [
                'warehouse' => $line->warehouse?->name,
                'product' => $line->product?->nom,
                'variant' => $line->productVariant?->sku,
                'stock' => $line->stock,
            ])->values()->all(),
        ];
    }
}
