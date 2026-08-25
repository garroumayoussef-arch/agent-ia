<?php

use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VtcRideReceiptController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Étape 5.9 : reçu récapitulatif imprimable d'une course VTC
    // confirmée. Hors du panel Filament (pas de layout admin, pensé
    // pour l'impression) — l'autorisation est vérifiée dans le
    // contrôleur, pas ici (cf. VtcRideReceiptController).
    Route::get('/vtc-rides/{vtcRide}/receipt', [VtcRideReceiptController::class, 'show'])
        ->name('vtc-rides.receipt');

    // Étape T23 — PDF d'une facture émise, régénéré à chaque appel
    // depuis les données figées d'Invoice/InvoiceLine (jamais depuis
    // SalesOrder/Customer/CompanySettings, contrainte 9). Hors du panel
    // Filament, même principe que le reçu VTC ci-dessus : l'autorisation
    // est vérifiée dans le contrôleur (cf. InvoicePdfController).
    Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'show'])
        ->name('invoices.pdf');
});

require __DIR__.'/auth.php';
Route::get('/admin/agent', function () {
    return 'Agent IA fonctionne !';
});
