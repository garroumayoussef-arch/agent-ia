<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Étape T19 (D5) — comble un manque préexistant depuis T11b/T16 : ce
 * formulaire n'exposait AUCUN champ warehouse_id, toute création
 * directe d'un mouvement retombant silencieusement sur l'entrepôt par
 * défaut (StockMovement::resolveDefaultWarehouseIdOrFail()). Sans ce
 * champ, le scoping par entrepôt (T19) serait sans effet sur ce point
 * d'entrée : un manager restreint pourrait toujours créer un mouvement
 * sur l'entrepôt par défaut via cette Resource. Les options sont
 * restreintes au périmètre de l'utilisateur courant
 * (ScopesToOwnWarehouses) — simple confort d'UI, la barrière autoritaire
 * reste CreateStockMovement::mutateFormDataBeforeCreate().
 */
class StockMovementForm
{
    use ScopesToOwnWarehouses;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produit')
                    ->options(function () {
                        return Product::query()
                            ->orderBy('nom')
                            ->pluck('nom', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('product_variant_id', null);
                    })
                    // Changer le produit d'un mouvement existant romprait
                    // l'historique de stock de deux cibles différentes :
                    // ce n'est autorisé qu'à la création (voir aussi le
                    // garde-fou correspondant dans StockMovement::updating()).
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated()
                    ->required(),

                Select::make('product_variant_id')
                    ->label('Variante')
                    ->options(function (Get $get) {
                        $productId = $get('product_id');

                        if (! $productId) {
                            return [];
                        }

                        return ProductVariant::query()
                            ->where('product_id', $productId)
                            ->orderBy('size')
                            ->orderBy('color')
                            ->get()
                            ->mapWithKeys(function (ProductVariant $variant) {
                                $parts = [];

                                if ($variant->size) {
                                    $parts[] = 'Taille : ' . $variant->size;
                                }

                                if ($variant->color) {
                                    $parts[] = 'Couleur : ' . $variant->color;
                                }

                                if ($variant->version) {
                                    $parts[] = 'Version : ' . $variant->version;
                                }

                                $label = implode(' / ', $parts);

                                if ($variant->sku) {
                                    $label .= ' — SKU : ' . $variant->sku;
                                }

                                $label .= ' — Stock : ' . $variant->stock;

                                return [
                                    $variant->id => $label,
                                ];
                            })
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    // Requise uniquement si le produit sélectionné possède
                    // réellement des variantes : un produit sans variante
                    // doit pouvoir recevoir un mouvement directement sur
                    // son stock global (cf. StockMovement::creating(), CAS 2).
                    ->disabled(fn (Get $get, string $operation): bool => $operation === 'edit'
                        || ! $get('product_id')
                        || ! static::productHasVariants($get('product_id')))
                    ->dehydrated()
                    ->required(fn (Get $get, string $operation): bool => $operation !== 'edit'
                        && static::productHasVariants($get('product_id')))
                    ->helperText(function (Get $get): string {
                        if (! $get('product_id')) {
                            return 'Sélectionnez d’abord un produit.';
                        }

                        if (static::productHasVariants($get('product_id'))) {
                            return 'Ce produit a des variantes : sélectionnez celle concernée par ce mouvement.';
                        }

                        return "Ce produit n'a pas de variantes : le mouvement s'appliquera directement sur son stock global.";
                    }),

                Select::make('warehouse_id')
                    ->label('Entrepôt')
                    ->options(fn () => static::activeWarehousesOptionsForCurrentUser())
                    ->default(function (): ?int {
                        $options = static::activeWarehousesOptionsForCurrentUser();

                        return count($options) === 1 ? array_key_first($options) : null;
                    })
                    ->searchable()
                    ->preload()
                    // Changer l'entrepôt d'un mouvement existant romprait
                    // son historique par-entrepôt, au même titre que
                    // product_id ci-dessus : autorisé uniquement à la
                    // création.
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated()
                    ->required()
                    ->helperText('Entrepôt concerné par ce mouvement. Résolu automatiquement en repli sur l\'entrepôt par défaut lorsqu\'un seul choix est possible.'),

                Select::make('type')
                    ->label('Type de mouvement')
                    ->options([
                        'purchase' => '🟢 Achat',
                        'sale' => '🔴 Vente',
                        'return' => '🟡 Retour',
                        'adjustment' => '🟠 Ajustement',
                        // 'transfer' retiré : le multi-entrepôts n'est pas encore implémenté,
                        // ce type est bloqué côté modèle (StockMovement) tant qu'il n'est pas prêt.
                        'inventory' => '⚪ Inventaire',
                    ])
                    ->live()
                    ->required(),

                TextInput::make('quantity')
                    ->label('Quantité')
                    ->numeric()
                    ->minValue(fn (Get $get): int => in_array($get('type'), ['adjustment', 'inventory'], true) ? 0 : 1)
                    ->helperText(fn (Get $get): string => in_array($get('type'), ['adjustment', 'inventory'], true)
                        ? 'Valeur absolue du stock après ce mouvement (0 autorisé).'
                        : 'Nombre d’unités concernées par ce mouvement.')
                    ->required(),

                TextInput::make('reference')
                    ->label('Référence')
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('Commentaire')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Un produit sans aucune variante peut recevoir un mouvement de
     * stock directement (cf. StockMovement CAS 2) ; un produit qui a
     * des variantes doit obligatoirement passer par l'une d'elles pour
     * ne pas rompre la synchronisation Product.stock <-> variantes.
     */
    private static function productHasVariants(?int $productId): bool
    {
        if (! $productId) {
            return false;
        }

        return ProductVariant::query()
            ->where('product_id', $productId)
            ->exists();
    }
}