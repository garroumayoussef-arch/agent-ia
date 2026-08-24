<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Représente le régime fiscal RÉELLEMENT appliqué par l'entreprise
 * pour une activité donnée — à distinguer du taux de référence lui-même
 * (qui vit dans TaxRate, ex. 10 % transport de voyageurs). Ce sont deux
 * informations différentes : le taux de référence ne change pas si
 * Magarrou passe de la franchise en base au régime réel, seule cette
 * ligne change (elle repointe vers un autre TaxRate).
 *
 * tax_rate_id peut être NULL : régime non configuré, aucune TVA ne
 * doit alors être présumée (ni 10 %, ni exonération).
 */
class FiscalSetting extends Model
{
    protected $guarded = [];

    public const ACTIVITY_VTC = 'vtc';

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
