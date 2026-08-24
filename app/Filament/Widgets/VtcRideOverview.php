<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Resources\VtcRides\Tables\VtcRidesTable;
use App\Models\VtcRide;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Étape 5.6b : dashboard VTC — statistiques des courses CONFIRMÉES
 * uniquement. Une course en brouillon n'a pas de revenu réalisé (ses
 * montants peuvent encore changer), et une course annulée n'en a jamais
 * eu ; ni l'une ni l'autre ne doit gonfler un chiffre d'affaires.
 * confirmed_at (étape 5.6a) sert de date de référence pour la période
 * "ce mois-ci" — exactement le rôle pour lequel elle a été créée
 * (performed_at et updated_at ne conviennent pas, cf. la migration qui
 * l'introduit).
 *
 * Scoping IDENTIQUE à VtcRideResource (étape 5.5), via ScopesToOwnDriver
 * (étape 5.6a) pour que la même règle d'accès ne vive jamais en deux
 * copies susceptibles de diverger :
 * - admin/manager : statistiques sur l'ensemble des chauffeurs.
 * - un utilisateur lié à un Driver : statistiques sur SES propres
 *   courses uniquement.
 * - ni l'un ni l'autre : le widget ne s'affiche pas du tout (canView).
 *
 * Étape 5.12b : ajout de deux périodes fixes supplémentaires (mois
 * dernier, cette année), en plus de "ce mois"/"total" déjà en place —
 * toujours des tuiles statiques, aucun sélecteur interactif (option 2a
 * retenue plutôt qu'un mécanisme de filtre, absent de ce projet et non
 * nécessaire ici).
 *
 * Aucune interaction avec StockMovement/PurchaseOrder/SalesOrder.
 */
class VtcRideOverview extends StatsOverviewWidget
{
    use ScopesToOwnDriver;

    public static function canView(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() !== null;
    }

    protected function getStats(): array
    {
        [$monthCount, $monthTotalTtc] = $this->countAndSumBetween(now()->startOfMonth(), now()->endOfMonth());

        [$lastMonthCount, $lastMonthTotalTtc] = $this->countAndSumBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        [$yearCount, $yearTotalTtc] = $this->countAndSumBetween(now()->startOfYear(), now()->endOfYear());

        $totalCount = $this->scopedConfirmedRidesQuery()->count();

        $totalTtc = $this->scopedConfirmedRidesQuery()->sum('total_ttc');

        return [
            Stat::make('Courses confirmées (ce mois)', $monthCount)
                ->description('Depuis le 1er du mois en cours')
                ->color('primary'),

            Stat::make('CA TTC (ce mois)', VtcRidesTable::formatMoneyOrDash((string) $monthTotalTtc))
                ->description('Chiffre d\'affaires TTC du mois en cours')
                ->color('success'),

            Stat::make('Courses confirmées (mois dernier)', $lastMonthCount)
                ->description('Mois calendaire précédent, entièrement clos')
                ->color('primary'),

            Stat::make('CA TTC (mois dernier)', VtcRidesTable::formatMoneyOrDash((string) $lastMonthTotalTtc))
                ->description('Chiffre d\'affaires TTC du mois calendaire précédent')
                ->color('success'),

            Stat::make('Courses confirmées (cette année)', $yearCount)
                ->description('Depuis le 1er janvier')
                ->color('primary'),

            Stat::make('CA TTC (cette année)', VtcRidesTable::formatMoneyOrDash((string) $yearTotalTtc))
                ->description('Chiffre d\'affaires TTC depuis le 1er janvier')
                ->color('success'),

            Stat::make('Courses confirmées (total)', $totalCount)
                ->description('Toutes courses confirmées, tout historique')
                ->color('primary'),

            Stat::make('CA TTC (total)', VtcRidesTable::formatMoneyOrDash((string) $totalTtc))
                ->description('Chiffre d\'affaires TTC de toutes les courses confirmées')
                ->color('success'),
        ];
    }

    /**
     * Étape 5.12b : tuiles fixes supplémentaires (mois dernier, cette
     * année), même principe que "ce mois"/"total" déjà en place —
     * aucun sélecteur interactif (décision explicite, option 2a
     * retenue plutôt que 2b). Toujours basé sur confirmed_at, jamais
     * performed_at (nullable, non fiable) ni is_active de
     * Driver/Vehicle : une course confirmée reste dans l'historique
     * statistique même si son chauffeur/véhicule devient inactif
     * ensuite ou si performed_at est NULL — scopedConfirmedRidesQuery()
     * ne filtre jamais sur ces deux champs, cf. son propre commentaire.
     *
     * @return array{0: int, 1: int|float}
     */
    private function countAndSumBetween($start, $end): array
    {
        $count = $this->scopedConfirmedRidesQuery()
            ->whereBetween('confirmed_at', [$start, $end])
            ->count();

        $totalTtc = $this->scopedConfirmedRidesQuery()
            ->whereBetween('confirmed_at', [$start, $end])
            ->sum('total_ttc');

        return [$count, $totalTtc];
    }

    /**
     * Reconstruit une requête fraîche à chaque appel — jamais réutilisée
     * entre deux agrégats — pour ne pas risquer qu'un ->count() ou
     * ->sum() précédent ait muté le Builder sous-jacent.
     *
     * Ne filtre QUE sur status = confirmed : une course confirmée a
     * nécessairement total_ttc renseigné (markAsConfirmed() exige un
     * tax_status résolu avant de confirmer), donc aucun filtre
     * supplémentaire sur total_ttc n'est nécessaire ici. Le scoping
     * admin/chauffeur reproduit exactement
     * VtcRideResource::getEloquentQuery() (même repli défensif si un
     * utilisateur passait ce point sans admin/manager ni Driver, ce que
     * canView() empêche déjà en amont).
     */
    private function scopedConfirmedRidesQuery(): Builder
    {
        $query = VtcRide::query()->where('status', VtcRide::STATUS_CONFIRMED);

        if (static::isAdminOrManager()) {
            return $query;
        }

        $driver = static::currentDriver();

        if ($driver === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('driver_id', $driver->id);
    }
}
