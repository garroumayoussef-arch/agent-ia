<?php

namespace App\Filament\Resources\SalesOrders\Tables;

use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\Customer;
use App\Models\SalesOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesOrdersTable
{
    // Chantier Dropshipping, Gap D — ordre de priorité verrouillé (Q1) :
    // ne jamais masquer une annulation, même si d'autres lignes de la
    // commande sont dans un état plus avancé.
    private const SOURCING_OVERVIEW_PRIORITY = [
        'cancelled',
        'non_sourced',
        'non_generated',
        'draft',
        'ordered',
        'partially_received',
        'received',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            // Chantier Dropshipping, Gap D — chargement explicite à
            // l'échelle de la liste (plusieurs commandes par page), sur
            // le même principe que SalesOrderInfolist.php (D2.7.2) qui ne
            // traite qu'un seul enregistrement : sans ce eager loading,
            // sourcingOverviewState() ci-dessous provoquerait un N+1 par
            // ligne de chaque commande affichée.
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'items.allocation.purchaseOrderItem.purchaseOrder',
                // Chantier Dropshipping, étape D2.12 — chargement
                // explicite au même niveau que la ligne ci-dessus, pour
                // que reallocationOverviewState() ci-dessous ne coûte
                // qu'une requête pour l'ensemble des lignes de toutes
                // les commandes affichées, jamais un N+1 par ligne.
                'items.allocation.replacesAllocation',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Client')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Lignes')
                    ->counts('items'),

                // Chantier Dropshipping, Gap D — visibilité READ-ONLY,
                // dans la liste, de l'état sourcing/achat fournisseur
                // déjà calculé ligne par ligne dans SalesOrderInfolist.php
                // (sourcing_status D2.7, purchase_order_status D2.8.2) :
                // agrégation en un seul badge par commande selon la
                // priorité fixe verrouillée (Gap D, Q1) — ne réévalue
                // aucune règle de sourcing, ne lit que ce qui est déjà
                // persisté par SalesOrderItemAllocation::recordFor()
                // (D2.4.7) et CreatePurchaseOrdersFromAllocations (D2.6).
                Tables\Columns\TextColumn::make('sourcing_overview')
                    ->label('Sourcing fournisseur')
                    ->badge()
                    ->state(fn (SalesOrder $record): string => static::sourcingOverviewState($record))
                    ->formatStateUsing(fn (string $state): string => static::sourcingOverviewLabel($state))
                    ->color(fn (string $state): string => static::sourcingOverviewColor($state)),

                // Chantier Dropshipping, étape D2.12 — visibilité
                // READ-ONLY, dans la liste, de la ré-allocation (D2.9)
                // déjà visible ligne par ligne dans SalesOrderInfolist.php
                // (reallocation_status, D2.10) : indicateur agrégé par
                // commande (au moins une ligne ré-allouée), ne réévalue
                // aucune règle, ne lit que SalesOrderItemAllocation::
                // replacesAllocation() (D2.9, inchangée). État null (donc
                // aucun badge affiché) si aucune ligne n'a jamais été
                // ré-allouée — même discipline que D2.10/D2.11.
                Tables\Columns\TextColumn::make('reallocation_overview')
                    ->label('Ré-allocation')
                    ->badge()
                    ->state(fn (SalesOrder $record): ?string => static::reallocationOverviewState($record)),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Date de commande')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        SalesOrder::STATUS_DRAFT => static::statusLabel(SalesOrder::STATUS_DRAFT),
                        SalesOrder::STATUS_CONFIRMED => static::statusLabel(SalesOrder::STATUS_CONFIRMED),
                        SalesOrder::STATUS_PARTIALLY_SHIPPED => static::statusLabel(SalesOrder::STATUS_PARTIALLY_SHIPPED),
                        SalesOrder::STATUS_SHIPPED => static::statusLabel(SalesOrder::STATUS_SHIPPED),
                        SalesOrder::STATUS_CANCELLED => static::statusLabel(SalesOrder::STATUS_CANCELLED),
                    ]),

                SelectFilter::make('customer_id')
                    ->label('Client')
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // Le modèle refuse la suppression d'une commande dont
                    // au moins une ligne a été expédiée (cf.
                    // SalesOrder::deleting) : on affiche une notification
                    // plutôt qu'une erreur brute.
                    ->action(function (SalesOrder $record) {
                        try {
                            $record->delete();

                            Notification::make()
                                ->title('Commande supprimée')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            SalesOrder::STATUS_DRAFT => 'Brouillon',
            SalesOrder::STATUS_CONFIRMED => 'Confirmée',
            SalesOrder::STATUS_PARTIALLY_SHIPPED => 'Partiellement expédiée',
            SalesOrder::STATUS_SHIPPED => 'Expédiée',
            SalesOrder::STATUS_CANCELLED => 'Annulée',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            SalesOrder::STATUS_DRAFT => 'gray',
            SalesOrder::STATUS_CONFIRMED => 'info',
            SalesOrder::STATUS_PARTIALLY_SHIPPED => 'warning',
            SalesOrder::STATUS_SHIPPED => 'success',
            SalesOrder::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Chantier Dropshipping, Gap D — état agrégé (une seule valeur par
     * commande) de sourcing/achat fournisseur, calculé sur l'ensemble des
     * lignes déjà chargées par modifyQueryUsing() ci-dessus. Applique la
     * priorité verrouillée (Gap D, Q1) : une seule ligne annulée suffit à
     * faire remonter 'cancelled' au niveau de la commande, quel que soit
     * l'état des autres lignes.
     */
    public static function sourcingOverviewState(SalesOrder $record): string
    {
        $states = $record->items->map(function ($item): string {
            $allocation = $item->allocation;

            if (! $allocation) {
                return 'non_sourced';
            }

            $purchaseOrder = $allocation->purchaseOrderItem?->purchaseOrder;

            return $purchaseOrder?->status ?? 'non_generated';
        });

        foreach (self::SOURCING_OVERVIEW_PRIORITY as $candidate) {
            if ($states->contains($candidate)) {
                return $candidate;
            }
        }

        // Commande sans ligne (cas dégénéré, non observé en pratique).
        return 'non_sourced';
    }

    public static function sourcingOverviewLabel(string $state): string
    {
        return match ($state) {
            'non_sourced' => 'Non sourcé',
            'non_generated' => 'Non généré',
            default => PurchaseOrdersTable::statusLabel($state),
        };
    }

    public static function sourcingOverviewColor(string $state): string
    {
        return match ($state) {
            'non_sourced' => 'warning',
            'non_generated' => 'gray',
            default => PurchaseOrdersTable::statusColor($state),
        };
    }

    /**
     * Chantier Dropshipping, étape D2.12 — indicateur agrégé (une seule
     * valeur par commande) de ré-allocation, calculé sur l'ensemble des
     * lignes déjà chargées par modifyQueryUsing() ci-dessus. Contrairement
     * à sourcingOverviewState() (toujours non-nul, une des 7 priorités
     * fixes s'applique toujours), ce booléen retourne explicitement null
     * dès qu'aucune ligne n'a jamais été ré-allouée : le badge Filament
     * ne s'affiche alors pas du tout, aucun état neutre inventé.
     */
    public static function reallocationOverviewState(SalesOrder $record): ?string
    {
        $hasReallocation = $record->items->contains(
            fn ($item): bool => $item->allocation?->replacesAllocation !== null
        );

        return $hasReallocation ? 'Ré-alloué' : null;
    }
}
