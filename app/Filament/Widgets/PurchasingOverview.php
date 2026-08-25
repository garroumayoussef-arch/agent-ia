<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Étape T29 — reporting achats : montant commandé TTC, nombre de bons
 * de commande, montant facturé par les fournisseurs TTC, et nombre de
 * factures fournisseurs, pour 4 périodes fixes (ce mois / mois dernier
 * / cette année / total). Gabarit direct de CommercialOverview (T27) :
 * mêmes 4 tuiles de période, même absence de sélecteur interactif.
 *
 * Contrairement à CommercialOverview (CA net = facturé − avoirs,
 * relation SOUSTRACTIVE entre Invoice et CreditNote), "montant commandé"
 * et "montant facturé" ne sont JAMAIS combinés ici : ce sont deux étapes
 * différentes de la même relation commerciale (engagement vs charge
 * documentée), pas deux montants indépendants à additionner ou
 * soustraire — affichés côte à côte, jamais fusionnés (décision 2,
 * validée : pas de 5e indicateur "écart").
 *
 * Périmètre strictement en lecture : aucune modification de
 * PurchaseOrder, PurchaseOrderItem, SupplierInvoice, aucune migration.
 * Aucun filtre supplémentaire (déjà la convention de ce projet).
 *
 * Droits d'accès : identiques à PurchaseOrderResource/SupplierInvoiceResource
 * (BlocksChauffeurReadAccess — ouvert à tout le monde sauf un compte
 * chauffeur), reproduits ici via ScopesToOwnDriver puisqu'un widget ne
 * bénéficie pas de canView($record) d'une Resource. Aucun scoping par
 * entrepôt : vérifié que purchase_orders n'a aucune colonne
 * warehouse_id et que PurchaseOrderResource ne surcharge pas
 * getEloquentQuery() — même absence de scoping que SupplierInvoice.
 */
class PurchasingOverview extends StatsOverviewWidget
{
    use ScopesToOwnDriver;

    // Juste après CommercialOverview (-7) : le reporting achats
    // complète le reporting commercial, il ne doit pas passer avant lui.
    protected static ?int $sort = -6;

    /**
     * Statuts de PurchaseOrder réputés "réellement commandés" (décision
     * 1, validée) : exclut draft (jamais confirmé auprès du
     * fournisseur, order_date encore null) ET cancelled (même si
     * order_date était déjà posé avant l'annulation — cancel() ne le
     * réinitialise pas, un simple filtre "order_date non nul" serait
     * donc insuffisant).
     */
    private const ORDERED_STATUSES = [
        PurchaseOrder::STATUS_ORDERED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        PurchaseOrder::STATUS_RECEIVED,
    ];

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
     * Les 4 indicateurs pour une période donnée (bornes incluses), ou
     * sur l'historique complet si $start/$end valent null — même
     * principe que CommercialOverview::periodStats().
     *
     * @return array<int, Stat>
     */
    private function periodStats(string $label, ?Carbon $start, ?Carbon $end): array
    {
        $orderQuery = PurchaseOrder::query()->whereIn('status', self::ORDERED_STATUSES);
        $invoiceQuery = SupplierInvoice::query();

        if ($start !== null && $end !== null) {
            $orderQuery->whereBetween('order_date', [$start, $end]);
            $invoiceQuery->whereBetween('invoice_date', [$start, $end]);
        }

        $orderedCount = $orderQuery->count();
        $orderedTtc = (float) $orderQuery->sum('total_ttc');
        $invoicedCount = $invoiceQuery->count();
        $invoicedTtc = (float) $invoiceQuery->sum('total_ttc');

        return [
            Stat::make("Montant commandé TTC ({$label})", static::formatMoney($orderedTtc))
                ->description('Bons de commande passés (hors brouillon/annulé)')
                ->color('primary'),

            Stat::make("Nombre de commandes ({$label})", $orderedCount)
                ->description('Bons de commande passés sur la période')
                ->color('primary'),

            Stat::make("Montant facturé TTC ({$label})", static::formatMoney($invoicedTtc))
                ->description('Factures fournisseurs enregistrées')
                ->color('warning'),

            Stat::make("Nombre de factures fournisseurs ({$label})", $invoicedCount)
                ->description('Factures fournisseurs enregistrées sur la période')
                ->color('warning'),
        ];
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}
