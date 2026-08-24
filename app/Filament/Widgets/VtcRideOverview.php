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
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthCount = $this->scopedConfirmedRidesQuery()
            ->whereBetween('confirmed_at', [$monthStart, $monthEnd])
            ->count();

        $monthTotalTtc = $this->scopedConfirmedRidesQuery()
            ->whereBetween('confirmed_at', [$monthStart, $monthEnd])
            ->sum('total_ttc');

        $totalCount = $this->scopedConfirmedRidesQuery()->count();

        $totalTtc = $this->scopedConfirmedRidesQuery()->sum('total_ttc');

        return [
            Stat::make('Courses confirmées (ce mois)', $monthCount)
                ->description('Depuis le 1er du mois en cours')
                ->color('primary'),

            Stat::make('CA TTC (ce mois)', VtcRidesTable::formatMoneyOrDash((string) $monthTotalTtc))
                ->description('Chiffre d\'affaires TTC du mois en cours')
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
