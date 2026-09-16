<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemReturn;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bon de commande')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence'),

                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => PurchaseOrdersTable::statusLabel($state))
                            ->color(fn (string $state): string => PurchaseOrdersTable::statusColor($state)),

                        TextEntry::make('supplier.name')
                            ->label('Fournisseur')
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
                            // Chantier Dropshipping, étape D2.8 (Gap A) —
                            // remplace la résolution par défaut de
                            // Filament (accès paresseux $record->items)
                            // par un chargement explicite, pour que
                            // allocation/salesOrderItem/salesOrder ne
                            // coûte qu'une requête pour l'ensemble des
                            // lignes, quel que soit leur nombre — même
                            // pattern que SalesOrderInfolist.php (D2.7.2).
                            ->state(fn (PurchaseOrder $record) => $record->items()
                                ->with(['allocation.salesOrderItem.salesOrder'])
                                ->get())
                            ->schema([
                                TextEntry::make('product.nom')
                                    ->label('Produit'),

                                TextEntry::make('productVariant')
                                    ->label('Variante')
                                    ->state(function (PurchaseOrderItem $record): string {
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

                                TextEntry::make('quantity_received')
                                    ->label('Reçu')
                                    ->badge()
                                    ->color(fn (PurchaseOrderItem $record): string => match (true) {
                                        $record->quantity_received >= $record->quantity_ordered => 'success',
                                        $record->quantity_received > 0 => 'warning',
                                        default => 'gray',
                                    }),

                                TextEntry::make('unit_price')
                                    ->label('Prix unitaire')
                                    ->money('EUR')
                                    ->placeholder('-'),

                                // Chantier Dropshipping, étape D2.8 (Gap A)
                                // — traçabilité READ-ONLY symétrique de
                                // D2.7 : origine SalesOrder de cette ligne
                                // si elle a été générée par
                                // CreatePurchaseOrdersFromAllocations
                                // (D2.6.3), lue via la relation existante
                                // PurchaseOrderItem::allocation(), sans
                                // aucun appel à ce service ni à
                                // SalesOrderItemAllocation::recordFor().
                                TextEntry::make('origin')
                                    ->label('Origine')
                                    ->state(function (PurchaseOrderItem $record): string {
                                        $salesOrder = $record->allocation?->salesOrderItem?->salesOrder;

                                        if (! $salesOrder) {
                                            return 'Achat direct';
                                        }

                                        return "Vente {$salesOrder->reference}";
                                    }),
                            ])
                            ->columns(6),
                    ]),

                // Chantier "bon de retour" (décision 4, validée) — section
                // manquante volontairement différée lors du chantier "retour
                // physique fournisseur" : symétrique exacte de la section
                // "Retours physiques" de CreditNoteInfolist. Passe par
                // PurchaseOrderItem::returns() (relation additive) : aucune
                // relation ajoutée sur PurchaseOrder lui-même, qui reste
                // intégralement inchangé.
                Section::make('Retours physiques')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.nom')
                                    ->label('Ligne')
                                    ->columnSpanFull(),

                                RepeatableEntry::make('returns')
                                    ->label('')
                                    ->columns(6)
                                    ->schema([
                                        TextEntry::make('returned_at')->label('Date')->date('d/m/Y'),
                                        TextEntry::make('quantity')->label('Qté retournée'),
                                        // PurchaseOrderItemReturn n'a pas de colonne
                                        // warehouse_id propre (contrairement à
                                        // CreditNoteLineReturn/condition) : l'entrepôt
                                        // n'existe que sur le StockMovement généré
                                        // (relation hasOne), jamais un champ direct.
                                        TextEntry::make('stockMovement.warehouse.name')->label('Entrepôt')->placeholder('-'),
                                        TextEntry::make('reason')->label('Motif')->placeholder('-'),
                                        TextEntry::make('user.name')->label('Enregistré par')->placeholder('-'),

                                        // Chantier "bon de retour" (décisions 1 à 6,
                                        // validées) — lien de téléchargement du PDF
                                        // du retour, régénéré à chaque appel (cf.
                                        // PurchaseOrderItemReturnPdfController).
                                        TextEntry::make('id')
                                            ->label('Bon de retour')
                                            ->formatStateUsing(fn (): string => 'Télécharger le PDF')
                                            ->color('primary')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->url(fn (PurchaseOrderItemReturn $record): string => route('purchase-order-item-returns.pdf', $record))
                                            ->openUrlInNewTab(),
                                    ])
                                    ->placeholder('Aucun retour enregistré pour cette ligne.'),
                            ]),
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
