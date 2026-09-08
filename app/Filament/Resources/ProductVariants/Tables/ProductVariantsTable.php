<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.nom')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('barcode')
                    ->label('Code-barres')
                    ->searchable()
                    ->placeholder('-'),

                // Etape 2.6.6 : affichage des 3 colonnes ci-dessous depuis
                // le systeme generique d'attributs (attributeMirrorValue),
                // colonnes dediees product_variants.size/color/version
                // conservees comme unique source d'ecriture, de tri
                // (->sortable() sur 'size' ci-dessous, inchange) et de
                // filtre (SelectFilter::make('size') plus bas, inchange).
                // Repli sur $state (valeur brute) quand aucune ligne
                // miroir n'existe : 'size'/'version' sont scopes
                // activity='sport' dans AttributeDefinitionSeeder, donc
                // jamais mirrores pour une variante Bebe/Moto/Artisanat -
                // sans ce repli, l'affichage deviendrait vide pour ces
                // activites, traitant implicitement Sport comme
                // l'activite centrale. Le placeholder '-' ci-dessus reste
                // fonctionnel : Filament l'evalue sur la valeur BRUTE de
                // la colonne, avant tout formatStateUsing, donc une
                // colonne deja vide en base continue d'afficher '-'
                // exactement comme avant. Aucune activite n'est
                // privilegiee ici.
                Tables\Columns\TextColumn::make('size')
                    ->label('Taille')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state, $record) => $record->attributeMirrorValue('size') ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('color')
                    ->label('Couleur')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state, $record) => $record->attributeMirrorValue('color') ?? $state),

                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state, $record) => $record->attributeMirrorValue('version') ?? $state),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state <= 0 => 'danger',
                        $state <= Product::LOW_STOCK_THRESHOLD => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('prix_vente')
                    ->label('Prix vente')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'out_of_stock' => 'Rupture de stock',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'out_of_stock' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Produit')
                    ->options(fn () => Product::query()->orderBy('nom')->pluck('nom', 'id')->toArray())
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'out_of_stock' => 'Rupture de stock',
                    ]),

                SelectFilter::make('size')
                    ->label('Taille')
                    ->options([
                        'XS' => 'XS',
                        'S' => 'S',
                        'M' => 'M',
                        'L' => 'L',
                        'XL' => 'XL',
                        '2XL' => '2XL',
                        '3XL' => '3XL',
                        '4XL' => '4XL',
                        '5XL' => '5XL',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
