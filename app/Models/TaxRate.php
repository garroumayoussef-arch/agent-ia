<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table de référence configurable pour la TVA : aucun taux n'est
 * jamais codé en dur dans PurchaseOrder(Item)/SalesOrder(Item), tout
 * passe par une ligne ici.
 *
 * `type` distingue structurellement deux natures différentes :
 * - TYPE_PERCENTAGE : un taux numérique s'applique (`rate` renseigné).
 * - TYPE_EXEMPT : aucune TVA n'est facturée, pour une raison légale
 *   précise (`legal_mention` renseigné, ex. franchise en base). Ceci
 *   ne doit JAMAIS être confondu avec un taux à 0 % : ce sont deux
 *   lignes de nature différente, pas la même valeur numérique.
 */
class TaxRate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_default_purchase' => 'boolean',
        'is_default_sale' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_EXEMPT = 'exempt';

    public function isExempt(): bool
    {
        return $this->type === self::TYPE_EXEMPT;
    }
}
