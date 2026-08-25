<?php

namespace App\Filament\Resources\StockMovements\Pages\Concerns;

use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Étape T12 — action d'en-tête "Nouveau transfert" sur ListStockMovements,
 * sur le même modèle que HasPurchaseOrderWorkflowActions/
 * HasSalesOrderWorkflowActions : la validation métier (source ≠
 * destination, quantité > 0, suffisance du stock à la source) est
 * entièrement déléguée à StockTransfer::execute() — jamais confiance
 * dans les valeurs envoyées par le navigateur. L'action se contente
 * d'appeler ce point d'entrée unique et d'afficher le résultat.
 *
 * Reste volontairement minimale, conformément à la décision validée :
 * pas de nouvelle Resource, pas de gestion complète des entrepôts, pas
 * d'action de transfert sur WarehouseResource.
 */
trait HasStockTransferAction
{
    protected function transferAction(): Action
    {
        return Action::make('transfer')
            ->label('Nouveau transfert')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            // Réutilise le mécanisme d'autorisation déjà en place
            // (HasRoleBasedAuthorization sur StockMovementResource) :
            // un transfert crée des StockMovement, donc il suit la
            // même règle que leur création directe (admin/manager).
            ->visible(fn (): bool => StockMovementResource::canCreate())
            ->schema([
                Select::make('product_id')
                    ->label('Produit')
                    ->options(fn () => Product::query()->orderBy('nom')->pluck('nom', 'id')->toArray())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('product_variant_id', null))
                    ->required(),

                Select::make('product_variant_id')
                    ->label('Variante')
                    ->options(function (Get $get) {
                        $productId = $get('product_id');

                        if (!$productId) {
                            return [];
                        }

                        return ProductVariant::query()
                            ->where('product_id', $productId)
                            ->orderBy('size')
                            ->orderBy('color')
                            ->get()
                            ->mapWithKeys(function (ProductVariant $variant) {
                                $parts = array_filter([
                                    $variant->size ? "Taille : {$variant->size}" : null,
                                    $variant->color ? "Couleur : {$variant->color}" : null,
                                ]);

                                $label = implode(' / ', $parts);

                                if ($variant->sku) {
                                    $label .= " — SKU : {$variant->sku}";
                                }

                                return [$variant->id => $label];
                            })
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    // Un produit sans variante n'a pas besoin de ce
                    // champ (transfert direct sur son stock global) —
                    // même règle que StockMovementForm.
                    ->visible(fn (Get $get): bool => $get('product_id')
                        && ProductVariant::where('product_id', $get('product_id'))->exists())
                    ->required(fn (Get $get): bool => $get('product_id')
                        && ProductVariant::where('product_id', $get('product_id'))->exists()),

                Select::make('from_warehouse_id')
                    ->label('Entrepôt source')
                    ->options(fn () => Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),

                Select::make('to_warehouse_id')
                    ->label('Entrepôt destination')
                    // Exclut l'entrepôt déjà choisi comme source de la
                    // liste : première barrière (UX), la barrière
                    // autoritaire reste StockTransfer::execute().
                    ->options(fn (Get $get) => Warehouse::where('is_active', true)
                        ->when($get('from_warehouse_id'), fn ($query, $fromId) => $query->whereKeyNot($fromId))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('quantity')
                    ->label('Quantité')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                TextInput::make('reference')
                    ->label('Référence')
                    ->maxLength(255),
            ])
            ->action(function (array $data) {
                try {
                    StockTransfer::execute($data);

                    Notification::make()
                        ->title('Transfert effectué')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    // Jamais d'exception brute affichée : le message
                    // métier levé par StockTransfer::execute()/
                    // StockMovement (stock insuffisant, entrepôts
                    // identiques...) est repris tel quel dans la
                    // notification.
                    Notification::make()
                        ->title('Transfert impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
