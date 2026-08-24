<?php

namespace App\Http\Controllers;

use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\VtcRide;
use Illuminate\Contracts\View\View;

/**
 * Étape 5.9 — reçu récapitulatif (PAS une facture légale, cf. la vue :
 * aucune donnée légale d'entreprise — SIRET, adresse, n° TVA — n'existe
 * dans le projet). Restitue uniquement des valeurs déjà calculées et
 * figées sur VtcRide, aucun recalcul (même principe que
 * VtcRideInfolist).
 *
 * Autorisation : réutilise VtcRideResource::canView() telle quelle —
 * la même règle admin/manager-ou-chauffeur-propriétaire (étapes 5.5/
 * 5.8), jamais redérivée séparément ici. Une route HTTP classique ne
 * bénéficie pas du scoping automatique de getEloquentQuery() (propre à
 * la résolution de route Filament) : {vtcRide} est résolu par le
 * binding Eloquent standard, PUIS explicitement revérifié.
 *
 * Deux codes de statut distincts, volontairement :
 * - 404 si l'utilisateur n'a pas le droit de voir cette course du tout
 *   (cohérent avec le choix déjà fait en 5.5 : "n'existe pas dans son
 *   périmètre", pas "refusé").
 * - 403 si la course lui est visible mais n'est pas confirmée : ce
 *   n'est pas un problème d'accès, le reçu n'existe simplement pas
 *   encore pour une course brouillon/annulée.
 */
class VtcRideReceiptController extends Controller
{
    public function show(VtcRide $vtcRide): View
    {
        abort_unless(VtcRideResource::canView($vtcRide), 404);
        abort_unless($vtcRide->status === VtcRide::STATUS_CONFIRMED, 403);

        return view('vtc-rides.receipt', ['ride' => $vtcRide]);
    }
}
