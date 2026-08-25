<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Models\CreditNote;
use App\Models\Invoice;
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
        $invoiceQuery = Invoice::query();
        $creditNoteQuery = CreditNote::query();

        if ($start !== null && $end !== null) {
            $invoiceQuery->whereBetween('issued_at', [$start, $end]);
            $creditNoteQuery->whereBetween('issued_at', [$start, $end]);
        }

        $invoiceCount = $invoiceQuery->count();
        $invoicedTtc = (float) $invoiceQuery->sum('total_ttc');
        $creditNotesTtc = (float) $creditNoteQuery->sum('total_ttc');
        $netTtc = $invoicedTtc - $creditNotesTtc;

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
        ];
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}
