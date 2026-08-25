<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Étape T24 — avoir/note de crédit, immuable après émission. Point
 * d'entrée UNIQUE de création : generateFromInvoice() — même
 * convention que Invoice::generateFromSalesOrder() (T23) et
 * StockTransfer::execute() (méthode statique sur le modèle, pas de
 * classe Service séparée, aucun dossier Services dans ce projet).
 *
 * D2 (validée) — les blocs vendeur/acheteur sont COPIÉS depuis
 * l'Invoice au moment de l'émission de l'avoir, JAMAIS relus depuis
 * CompanySettings::current()/Customer (qui pourraient avoir changé
 * depuis l'émission de la facture) : une seule source de vérité pour
 * ces données (l'Invoice déjà figée), jamais une seconde lecture
 * indépendante.
 */
class CreditNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date',
        'invoice_issued_at_reference' => 'date',
        'seller_share_capital' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    public const SCOPE_TOTAL = 'total';

    public const SCOPE_PARTIAL = 'partial';

    public const SETTLEMENT_REFUND = 'refund';

    public const SETTLEMENT_FUTURE_INVOICE = 'future_invoice';

    public const STATUS_ISSUED = 'issued';

    /*
     * =================================================================
     * IMMUABILITÉ (contrainte T24 impérative)
     * =================================================================
     */

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \Exception(
                'Un avoir ne peut pas être modifié après son émission.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un avoir ne peut pas être supprimé après son émission.'
            );
        });
    }

    /*
     * =================================================================
     * GÉNÉRATION (point d'entrée unique)
     * =================================================================
     */

    /**
     * Lignes de la facture PAS ENCORE créditées par un avoir existant —
     * calculé à la demande (jamais un champ stocké, pour ne jamais
     * pouvoir se désynchroniser). Réutilisé à la fois pour proposer les
     * options des actions Filament (confort d'UI) et comme première
     * barrière de generateFromInvoice() ci-dessous.
     *
     * @return Collection<int, InvoiceLine>
     */
    public static function creditableLinesFor(Invoice $invoice): Collection
    {
        $allLines = $invoice->lines()->get();

        $alreadyCreditedLineIds = CreditNoteLine::query()
            ->whereIn('invoice_line_id', $allLines->pluck('id'))
            ->pluck('invoice_line_id');

        return $allLines->whereNotIn('id', $alreadyCreditedLineIds)->values();
    }

    /**
     * Génère un avoir portant sur tout ou partie des lignes d'une
     * facture. Jamais confiance dans les IDs de ligne venus de
     * l'appelant : revérifiés intégralement ici (règle impérative T24 :
     * "empêcher qu'une même ligne soit créditée deux fois ou qu'un
     * ensemble d'avoirs successifs dépasse le montant créditable").
     *
     * Barrière à trois niveaux (jamais un seul) :
     * 1. UI — les options proposées ne contiennent déjà que
     *    creditableLinesFor() (confort, jamais la seule protection) ;
     * 2. ICI — revérification applicative complète, avant toute
     *    écriture ;
     * 3. Base de données — contrainte UNIQUE sur
     *    credit_note_lines.invoice_line_id (migration), rempart final
     *    même en cas de contournement des deux premiers niveaux.
     *
     * @param  array<int, int>  $invoiceLineIds
     */
    public static function generateFromInvoice(
        Invoice $invoice,
        array $invoiceLineIds,
        string $reason,
        string $settlementType,
    ): self {
        if (blank($reason)) {
            throw new \Exception("Le motif de l'avoir est obligatoire.");
        }

        if (! in_array($settlementType, [self::SETTLEMENT_REFUND, self::SETTLEMENT_FUTURE_INVOICE], true)) {
            throw new \Exception(
                "Le mode de règlement de l'avoir doit être précisé (remboursement ou imputation sur facture future)."
            );
        }

        $invoiceLineIds = array_values(array_unique(array_map('intval', $invoiceLineIds)));

        if ($invoiceLineIds === []) {
            throw new \Exception('Sélectionnez au moins une ligne à créditer.');
        }

        $allLines = $invoice->lines()->get()->keyBy('id');

        foreach ($invoiceLineIds as $lineId) {
            if (! $allLines->has($lineId)) {
                throw new \Exception("Une des lignes sélectionnées n'appartient pas à cette facture.");
            }
        }

        $alreadyCreditedLineIds = CreditNoteLine::query()
            ->whereIn('invoice_line_id', $allLines->keys())
            ->pluck('invoice_line_id')
            ->all();

        if (count($alreadyCreditedLineIds) === $allLines->count()) {
            throw new \Exception('Cette facture est déjà intégralement créditée.');
        }

        if (array_intersect($invoiceLineIds, $alreadyCreditedLineIds) !== []) {
            throw new \Exception(
                'Une ou plusieurs lignes sélectionnées ont déjà été créditées par un avoir précédent.'
            );
        }

        $scope = count($invoiceLineIds) === $allLines->count() ? self::SCOPE_TOTAL : self::SCOPE_PARTIAL;

        return DB::transaction(function () use ($invoice, $invoiceLineIds, $allLines, $reason, $settlementType, $scope) {
            $creditedLines = $allLines->only($invoiceLineIds);

            $companySettings = CompanySettings::current();

            // Le préfixe de numérotation est une convention administrative,
            // pas une donnée d'identité légale : à la différence des blocs
            // vendeur/acheteur (D2, copiés depuis Invoice), il est lu tel
            // que configuré aujourd'hui, sans que cela ne viole D2.
            $number = CreditNoteSequence::nextNumber(
                (int) now()->format('Y'),
                $companySettings->credit_note_number_prefix ?: 'AV',
            );

            $creditNote = static::create([
                'invoice_id' => $invoice->id,
                'number' => $number,
                // Point 4 (validé) — toujours la date du jour, jamais
                // antidatée, quelle que soit la date de la facture.
                'issued_at' => now()->toDateString(),
                'scope' => $scope,
                'reason' => $reason,
                'settlement_type' => $settlementType,

                'seller_legal_name' => $invoice->seller_legal_name,
                'seller_legal_form' => $invoice->seller_legal_form,
                'seller_share_capital' => $invoice->seller_share_capital,
                'seller_address' => $invoice->seller_address,
                'seller_postal_code' => $invoice->seller_postal_code,
                'seller_city' => $invoice->seller_city,
                'seller_country' => $invoice->seller_country,
                'seller_siren' => $invoice->seller_siren,
                'seller_siret' => $invoice->seller_siret,
                'seller_rcs_city' => $invoice->seller_rcs_city,
                'seller_vat_number' => $invoice->seller_vat_number,

                'customer_id' => $invoice->customer_id,
                'customer_type' => $invoice->customer_type,
                'customer_name' => $invoice->customer_name,
                'customer_company' => $invoice->customer_company,
                'customer_address' => $invoice->customer_address,
                'customer_postal_code' => $invoice->customer_postal_code,
                'customer_city' => $invoice->customer_city,
                'customer_country' => $invoice->customer_country,
                'customer_siren' => $invoice->customer_siren,
                'customer_vat_number' => $invoice->customer_vat_number,

                'invoice_number_reference' => $invoice->number,
                'invoice_issued_at_reference' => $invoice->issued_at,
                'sales_order_reference' => $invoice->sales_order_reference,

                // Sommes des lignes créditées par CET avoir uniquement —
                // jamais recalculées différemment, jamais de discount_amount
                // séparé (la remise est déjà répercutée dans le tax_amount
                // de chaque InvoiceLine depuis T23).
                'total_ht' => $creditedLines->sum('subtotal_ht'),
                'tax_amount' => $creditedLines->sum('tax_amount'),
                'total_ttc' => $creditedLines->sum('total_ttc'),

                'user_id' => auth()->id(),
                'status' => self::STATUS_ISSUED,
            ]);

            foreach ($creditedLines as $line) {
                CreditNoteLine::create([
                    'credit_note_id' => $creditNote->id,
                    'invoice_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'product_name' => $line->product_name,
                    'variant_description' => $line->variant_description,
                    'quantity' => $line->quantity,
                    'unit_price_ht' => $line->unit_price_ht,
                    'subtotal_ht' => $line->subtotal_ht,
                    'tax_rate' => $line->tax_rate,
                    'tax_amount' => $line->tax_amount,
                    'total_ttc' => $line->total_ttc,
                ]);
            }

            return $creditNote;
        });
    }

    /*
     * =================================================================
     * RELATIONS
     * =================================================================
     */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
