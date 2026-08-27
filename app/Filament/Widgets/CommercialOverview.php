<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Étape T27 — reporting commercial/financier : CA TTC facturé, nombre
 * de factures, montant des avoirs émis, et CA net (CA TTC − avoirs),
 * pour 4 périodes fixes (ce mois / mois dernier / cette année / total).
 * Gabarit directement repris de VtcRideOverview (5.6b/5.12b) : mêmes
 * 4 tuiles de période, même absence de sélecteur interactif (convention
 * déjà en place dans ce projet, non introduite ici).
 *
 * Périmètre strictement en lecture : aucune modification d'Invoice,
 * CreditNote, InvoiceLine, CreditNoteLine, aucune migration. Aucun
 * filtre supplémentaire (client/produit/statut) — un statut unique
 * (`issued`) existe pour Invoice, un filtre par statut n'aurait rien à
 * filtrer.
 *
 * Droits d'accès : identiques à InvoiceResource/CreditNoteResource
 * (BlocksChauffeurReadAccess — ouvert à tout le monde sauf un compte
 * chauffeur), reproduits ici via ScopesToOwnDriver puisqu'un widget ne
 * bénéficie pas de canView($record) d'une Resource. Aucun scoping par
 * entrepôt (D4 T24 : facturation/avoirs restent hors périmètre
 * ScopesToOwnWarehouses).
 *
 * ============================================================
 * Chantier "facturation légale VTC" (D10, validé)
 * ============================================================
 * Depuis ce chantier, Invoice peut aussi avoir pour origine une VtcRide
 * (D1) — jamais comptée ici : ce widget reste EXCLUSIVEMENT le
 * reporting commercial de la vente marchandise, l'activité VTC ayant
 * son propre widget dédié (VtcRideOverview, qui mesure les courses
 * CONFIRMÉES, facturées ou non — une notion différente de "facturé").
 * Fusionner les deux produirait un CA VTC différent entre les deux
 * widgets (l'un compte les courses confirmées, l'autre compterait
 * seulement celles facturées), source de confusion en lecture de
 * dashboard — jamais fait ici, sciemment. Toutes les requêtes
 * ci-dessous filtrent donc explicitement whereNotNull('sales_order_id')
 * (ou l'équivalent via la relation invoice) : un oubli sur l'une
 * d'elles ferait apparaître silencieusement du CA VTC ici sans qu'aucun
 * test ne s'en aperçoive autrement qu'en le vérifiant explicitement
 * (cf. CommercialOverviewTest).
 *
 * ============================================================
 * Chantier A — trésorerie (D1-D4 validés)
 * ============================================================
 * Deux indicateurs supplémentaires ajoutés aux 4 déjà existants
 * ci-dessus (jamais retirés ni modifiés) :
 * - "Encaissé" (D1) : ancré sur InvoicePayment.paid_at — la date RÉELLE
 *   d'encaissement, jamais issued_at de la facture. Un paiement reçu ce
 *   mois-ci pour une facture émise le mois dernier compte dans le mois
 *   en cours, pas dans le mois d'émission de la facture.
 * - "Restant dû" (D3) : scopé aux factures ÉMISES pendant la période
 *   (même ancrage qu'invoicedTtc ci-dessous, jamais un solde global à
 *   date) — les paiements reçus contre CES factures sont comptés quelle
 *   que soit la date de CES paiements (cohérent avec
 *   Invoice::amountRemaining(), qui ne filtre jamais ses paiements par
 *   date). Pour la période "total" (bornes nulles, comme les 4 autres
 *   tuiles), ce calcul redonne mécaniquement le solde dû à ce jour sur
 *   la totalité des factures — pas un comportement spécial introduit
 *   ici, simple conséquence du gabarit à bornes nulles déjà en place.
 *
 * ============================================================
 * Chantier "réconciliation avoirs" (D1/D3/D6/D7, validés)
 * ============================================================
 * La limite ci-dessus (héritée de T31) est désormais corrigée : la
 * tuile "Restant dû" retranche également les avoirs (CreditNote),
 * jamais seulement les paiements, avec la même formule NETTE que
 * Invoice::amountRemaining() (D1), plafonnée à 0 (D3) — reproduite ici
 * à l'identique (D6 : duplication contrôlée assumée, aucun helper
 * partagé introduit).
 *
 * D7 (validé) — ancrage temporel des avoirs dans cette tuile
 * uniquement : les avoirs comptés sont ceux liés aux factures ÉMISES
 * pendant la période (même ancrage que invoicedTtc/paidOnPeriodInvoices
 * ci-dessous), quelle que soit la date d'émission de l'avoir
 * lui-même — jamais l'ancrage de la tuile "Montant des avoirs"
 * ci-dessus, qui reste volontairement distincte (issued_at de
 * l'avoir). Un avoir émis APRÈS la période mais portant sur une
 * facture émise PENDANT la période compte donc toujours dans "Restant
 * dû" de cette période, mais jamais dans "Montant des avoirs" de
 * cette même période.
 */
class CommercialOverview extends StatsOverviewWidget
{
    use ScopesToOwnDriver;

    // Juste après WarehouseStockOverview (-8) : le reporting commercial
    // complète le reporting stock, il ne doit pas passer avant lui.
    protected static ?int $sort = -7;

    public static function canView(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() === null;
    }

    protected function getStats(): array
    {
        return [
            ...$this->periodStats('ce mois', now()->startOfMonth(), now()->endOfMonth()),
            ...$this->periodStats('mois dernier', now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()),
            ...$this->periodStats('cette année', now()->startOfYear(), now()->endOfYear()),
            ...$this->periodStats('total', null, null),
        ];
    }

    /**
     * Les 4 indicateurs pour une période donnée (bornes incluses sur
     * `issued_at`), ou sur l'historique complet si $start/$end valent
     * null — même principe que VtcRideOverview::scopedConfirmedRidesQuery()
     * (aucune borne = pas de whereBetween du tout, jamais une plage
     * artificiellement large).
     *
     * @return array<int, Stat>
     */
    private function periodStats(string $label, ?Carbon $start, ?Carbon $end): array
    {
        // D10 (validé) — exclut explicitement les factures/avoirs
        // d'origine VTC (cf. documentation de tête de classe) : ce
        // widget reste exclusivement le CA vente marchandise.
        $invoiceQuery = Invoice::query()->whereNotNull('sales_order_id');
        $creditNoteQuery = CreditNote::query()->whereHas(
            'invoice',
            fn ($invoiceQuery) => $invoiceQuery->whereNotNull('sales_order_id'),
        );

        if ($start !== null && $end !== null) {
            $invoiceQuery->whereBetween('issued_at', [$start, $end]);
            $creditNoteQuery->whereBetween('issued_at', [$start, $end]);
        }

        $invoiceCount = $invoiceQuery->count();
        $invoicedTtc = (float) $invoiceQuery->sum('total_ttc');
        $creditNotesTtc = (float) $creditNoteQuery->sum('total_ttc');
        $netTtc = $invoicedTtc - $creditNotesTtc;

        // Chantier A (D1) — "Encaissé" : ancré sur paid_at (date réelle
        // d'encaissement), jamais sur issued_at de la facture (cf.
        // documentation de tête de classe). D10 (validé) — exclut les
        // paiements sur une facture VTC, même principe que ci-dessus.
        $paymentQuery = InvoicePayment::query()->whereHas(
            'invoice',
            fn ($invoiceQuery) => $invoiceQuery->whereNotNull('sales_order_id'),
        );

        if ($start !== null && $end !== null) {
            $paymentQuery->whereBetween('paid_at', [$start, $end]);
        }

        $collectedTtc = (float) $paymentQuery->sum('amount');

        // Chantier A (D3) — "Restant dû" : scopé aux factures ÉMISES
        // pendant la période (même ancrage qu'invoicedTtc ci-dessus),
        // paiements comptés sans filtre de date (cf. documentation de
        // tête de classe).
        $paidOnPeriodInvoices = (float) InvoicePayment::query()
            ->whereHas('invoice', function ($invoiceQuery) use ($start, $end) {
                // D10 (validé) — exclut les factures VTC, même principe
                // que ci-dessus.
                $invoiceQuery->whereNotNull('sales_order_id');

                if ($start !== null && $end !== null) {
                    $invoiceQuery->whereBetween('issued_at', [$start, $end]);
                }
            })
            ->sum('amount');

        // Chantier "réconciliation avoirs" (D1/D7, validés) — avoirs
        // liés aux factures ÉMISES pendant la période (même ancrage que
        // $paidOnPeriodInvoices ci-dessus), JAMAIS filtrés sur la date
        // d'émission de l'avoir lui-même (cf. documentation de tête de
        // classe, D7) : un avoir émis après la période mais portant sur
        // une facture de la période est donc bien compté ici.
        $creditedOnPeriodInvoicesTtc = (float) CreditNote::query()
            ->whereHas('invoice', function ($invoiceQuery) use ($start, $end) {
                // D10 (validé) — exclut les avoirs sur facture VTC, même
                // principe que ci-dessus.
                $invoiceQuery->whereNotNull('sales_order_id');

                if ($start !== null && $end !== null) {
                    $invoiceQuery->whereBetween('issued_at', [$start, $end]);
                }
            })
            ->sum('total_ttc');

        // D1 (validé) — reste dû = total_ttc − avoirs − paiements,
        // même formule que Invoice::amountRemaining(). D3 (validé) —
        // plafonné à 0, jamais négatif (un éventuel excédent est un
        // solde créditeur, hors périmètre de cette tuile).
        $netInvoicedOnPeriodTtc = round($invoicedTtc - $creditedOnPeriodInvoicesTtc, 2);
        $remainingTtc = max(0.0, round($netInvoicedOnPeriodTtc - $paidOnPeriodInvoices, 2));

        return [
            Stat::make("CA TTC facturé ({$label})", static::formatMoney($invoicedTtc))
                ->description('Somme des factures émises')
                ->color('success'),

            Stat::make("Nombre de factures ({$label})", $invoiceCount)
                ->description('Factures émises sur la période')
                ->color('primary'),

            Stat::make("Montant des avoirs ({$label})", static::formatMoney($creditNotesTtc))
                ->description('Somme des avoirs émis')
                ->color('danger'),

            Stat::make("CA net ({$label})", static::formatMoney($netTtc))
                ->description('CA TTC facturé − avoirs émis')
                ->color('warning'),

            Stat::make("Encaissé ({$label})", static::formatMoney($collectedTtc))
                ->description('Paiements clients reçus sur la période')
                ->color('success'),

            Stat::make("Restant dû ({$label})", static::formatMoney($remainingTtc))
                ->description('Sur les factures émises sur la période, net des avoirs')
                ->color('danger'),
        ];
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}
