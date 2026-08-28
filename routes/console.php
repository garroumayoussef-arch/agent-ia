<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chantier "Notifications & communication" V1 (D1/D6, validés) —
// première tâche planifiée de ce projet. Digest quotidien de stock bas
// par admin/manager (cf. SendLowStockAlerts, anti-dup via
// NotificationLog : jamais plus d'un envoi par utilisateur et par jour,
// même si le scheduler est déclenché plusieurs fois le même jour).
Schedule::command('notifications:low-stock-alerts')->daily();
