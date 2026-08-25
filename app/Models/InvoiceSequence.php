<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Étape T23 (D6) — compteur de numérotation légale par année civile.
 * Aucune Resource Filament dédiée : purement interne, utilisé
 * exclusivement par Invoice::generateFromSalesOrder().
 */
class InvoiceSequence extends Model
{
    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'last_number' => 'integer',
    ];

    /**
     * Génère le prochain numéro de facture pour l'année donnée, de
     * façon strictement unique et protégée contre les créations
     * concurrentes (D6) :
     * - insertOrIgnore() garantit l'existence de la ligne de l'année
     *   sans jamais lever d'exception si deux processus tentent de la
     *   créer en même temps (contrainte unique sur `year`, le doublon
     *   est simplement ignoré plutôt que de provoquer une erreur).
     * - lockForUpdate() verrouille ensuite cette ligne pour la durée de
     *   la transaction englobante (celle de
     *   Invoice::generateFromSalesOrder()) : deux générations
     *   concurrentes sont sérialisées par la base, jamais de doublon ni
     *   de trou dans la séquence.
     */
    public static function nextNumber(int $year, string $prefix): string
    {
        static::query()->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = static::query()
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return sprintf('%s-%d-%06d', $prefix, $year, $sequence->last_number);
    }
}
