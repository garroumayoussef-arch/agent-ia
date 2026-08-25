<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Étape T23 — identité légale de Magarrou et régime fiscal, configurés
 * par l'admin. Table "singleton" : une seule ligne existe jamais
 * (id=1), garantie par current() ci-dessous — jamais de formulaire de
 * création, une seule page de réglages (CompanySettingsPage).
 *
 * D5 — aucune mention fiscale n'est jamais déduite/supposée par le
 * code : vat_regime doit être explicitement configuré, et
 * assertReadyForInvoicing() refuse toute génération de facture tant
 * que les informations légales nécessaires ne sont pas complètes.
 */
class CompanySettings extends Model
{
    protected $table = 'company_settings';

    protected $guarded = [];

    protected $casts = [
        'share_capital' => 'decimal:2',
        'recovery_indemnity_amount' => 'decimal:2',
        'default_payment_terms_days' => 'integer',
    ];

    public const VAT_REGIME_STANDARD = 'standard';

    public const VAT_REGIME_FRANCHISE = 'franchise_en_base';

    /**
     * Accès à l'unique ligne de configuration, créée à la volée (tous
     * champs NULL) si elle n'existe pas encore — jamais plusieurs
     * lignes, jamais de formulaire de création exposé à l'utilisateur.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * Barrière autoritaire (D5) : refuse toute génération de facture
     * tant que les informations légales minimales ne sont pas
     * complètes. Jamais de valeur par défaut supposée à la place d'un
     * champ manquant — seul un message explicite énumérant ce qui
     * manque, pour que l'admin corrige dans CompanySettingsPage.
     */
    public function assertReadyForInvoicing(): void
    {
        $missing = [];

        foreach (['legal_name', 'address', 'postal_code', 'city', 'country', 'siren', 'vat_regime'] as $field) {
            if (blank($this->{$field})) {
                $missing[] = $field;
            }
        }

        if ($this->vat_regime === self::VAT_REGIME_STANDARD && blank($this->vat_number)) {
            $missing[] = 'vat_number';
        }

        if ($this->vat_regime === self::VAT_REGIME_FRANCHISE && blank($this->vat_exemption_mention)) {
            $missing[] = 'vat_exemption_mention';
        }

        if ($missing !== []) {
            throw new \Exception(
                "Impossible de générer une facture : les informations légales de l'entreprise sont incomplètes dans Paramètres de facturation (champs manquants : ".implode(', ', $missing).').'
            );
        }
    }
}
