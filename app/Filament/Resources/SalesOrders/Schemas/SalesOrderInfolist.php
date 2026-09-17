<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalesOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Commande')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence'),

                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => SalesOrdersTable::statusLabel($state))
                            ->color(fn (string $state): string => SalesOrdersTable::statusColor($state)),

                        TextEntry::make('customer.name')
                            ->label('Client')
                            ->placeholder('-'),

                        TextEntry::make('order_date')
                            ->label('Date de commande')
                            ->date('d/m/Y')
                            ->placeholder('-'),

                        TextEntry::make('user.name')
                            ->label('Créé par')
                            ->placeholder('Système / import'),

                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Lignes commandées')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            // Chantier Dropshipping, étape D2.7.2 —
                            // remplace la résolution par défaut de
                            // Filament (accès paresseux $record->items,
                            // une requête par relation supplémentaire et
                            // par ligne) par un chargement explicite,
                            // pour que allocation/supplierProductSourcing/
                            // supplier et allocation/purchaseOrderItem/
                            // purchaseOrder ne coûtent qu'une requête
                            // chacun pour l'ensemble des lignes, quel que
                            // soit leur nombre.
                            ->state(fn (SalesOrder $record) => $record->items()
                                ->with([
                                    'allocation.supplierProductSourcing.supplier',
                                    'allocation.purchaseOrderItem.purchaseOrder',
                                    // Chantier Dropshipping, étape D2.10 —
                                    // traçabilité READ-ONLY de la
                                    // ré-allocation (D2.9) : chargement
                                    // explicite du même niveau que les
                                    // deux lignes ci-dessus, pour que
                                    // allocation.replacesAllocation ne
                                    // coûte qu'une requête pour
                                    // l'ensemble des lignes.
                                    'allocation.replacesAllocation.supplierProductSourcing.supplier',
                                ])
                                ->get())
                            ->schema([
                                TextEntry::make('product.nom')
                                    ->label('Produit'),

                                TextEntry::make('productVariant')
                                    ->label('Variante')
                                    ->state(function (SalesOrderItem $record): string {
                                        $variant = $record->productVariant;

                                        if (! $variant) {
                                            return '-';
                                        }

                                        return implode(' / ', array_filter([
                                            $variant->attributeMirrorValue('size'),
                                            $variant->attributeMirrorValue('color'),
                                        ])) ?: ($variant->sku ?? '-');
                                    }),

                                TextEntry::make('quantity_ordered')
                                    ->label('Commandé'),

                                TextEntry::make('quantity_shipped')
                                    ->label('Expédié')
                                    ->badge()
                                    ->color(fn (SalesOrderItem $record): string => match (true) {
                                        $record->quantity_shipped >= $record->quantity_ordered => 'success',
                                        $record->quantity_shipped > 0 => 'warning',
                                        default => 'gray',
                                    }),

                                TextEntry::make('unit_price')
                                    ->label('Prix unitaire')
                                    ->money('EUR')
                                    ->placeholder('-'),

                                // Chantier Dropshipping, étape D2.7.2 —
                                // traçabilité READ-ONLY du sourcing
                                // fournisseur (D2.4.7) et de la commande
                                // fournisseur éventuellement générée
                                // (D2.6.3), sans aucune nouvelle règle de
                                // sélection fournisseur ni de génération
                                // de PurchaseOrder : la donnée est lue
                                // telle que déjà décidée/persistée par
                                // SalesOrderItemAllocation::recordFor()
                                // et CreatePurchaseOrdersFromAllocations.
                                TextEntry::make('sourcing_status')
                                    ->label('Sourcing fournisseur')
                                    ->state(function (SalesOrderItem $record): string {
                                        $allocation = $record->allocation;

                                        if (! $allocation) {
                                            return 'Non sourcé';
                                        }

                                        $purchaseOrder = $allocation->purchaseOrderItem?->purchaseOrder;

                                        if (! $purchaseOrder) {
                                            return 'Non généré';
                                        }

                                        return $allocation->supplierProductSourcing?->supplier?->name
                                            .' — '.$purchaseOrder->reference;
                                    }),

                                // Chantier Dropshipping, étape D2.8.2
                                // (Gap B) — statut réel de l'achat
                                // fournisseur généré (D2.6.3), distinct
                                // de sourcing_status ci-dessus qui ne
                                // s'appuie pas sur cette information :
                                // permet notamment de distinguer un
                                // achat annulé (PurchaseOrder::cancel())
                                // d'un achat actif. Lit le PurchaseOrder
                                // déjà eager-chargé par le ->state() du
                                // RepeatableEntry ci-dessus (aucun N+1).
                                TextEntry::make('purchase_order_status')
                                    ->label('Statut achat fournisseur')
                                    ->badge()
                                    ->placeholder('-')
                                    ->state(fn (SalesOrderItem $record): ?string => $record->allocation?->purchaseOrderItem?->purchaseOrder?->status)
                                    ->formatStateUsing(fn (?string $state): ?string => PurchaseOrdersTable::statusLabel($state))
                                    ->color(fn (?string $state): ?string => PurchaseOrdersTable::statusColor($state)),

                                // Chantier Dropshipping, étape D2.10 —
                                // traçabilité READ-ONLY de la
                                // ré-allocation manuelle vers un
                                // fournisseur alternatif (D2.9,
                                // SalesOrderItemAllocation::reallocateFor(),
                                // inchangée). Source de vérité unique :
                                // SalesOrderItemAllocation::
                                // replacesAllocation() (D2.9, additive,
                                // inchangée) — jamais replacedBy(),
                                // toujours null sur l'allocation ACTIVE
                                // retournée par SalesOrderItem::
                                // allocation() (hasOne()->latestOfMany(),
                                // D2.9). ->visible() (et non
                                // ->placeholder()) : une ligne jamais
                                // ré-allouée n'affiche RIEN, pas un tiret
                                // - contrairement à purchase_order_status
                                // ci-dessus. Gap C (PurchaseOrder
                                // regroupant plusieurs SalesOrder
                                // distincts) explicitement hors périmètre,
                                // non traité ici.
                                TextEntry::make('reallocation_status')
                                    ->label('Ré-allocation')
                                    ->visible(fn (SalesOrderItem $record): bool => $record->allocation?->replacesAllocation !== null)
                                    ->state(function (SalesOrderItem $record): ?string {
                                        $previous = $record->allocation?->replacesAllocation;

                                        return $previous !== null
                                            ? 'Ré-alloué depuis '.($previous->supplierProductSourcing?->supplier?->name ?? '-')
                                            : null;
                                    }),
                            ])
                            ->columns(8),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('-'),
                    ])
                    ->visible(fn ($record): bool => filled($record?->notes)),
            ]);
    }
}
