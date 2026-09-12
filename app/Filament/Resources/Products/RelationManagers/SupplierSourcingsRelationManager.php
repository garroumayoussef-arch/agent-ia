<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Chantier Dropshipping, étape D2.3.2 — interface Filament du sourcing
 * produit → fournisseur, côté ProductResource. S'appuie EXCLUSIVEMENT sur
 * la relation Eloquent déjà existante Product::supplierSourcings() (étape
 * D1) : aucune nouvelle relation, aucune requête reconstruite. Caractérisé
 * par tests/Feature/SupplierSourcingsRelationManagerTest.php (D2.3.1),
 * écrit avant ce fichier.
 *
 * Volontairement neutre vis-à-vis des activités : aucun filtre, aucune
 * condition sur `activity` nulle part dans ce fichier (cohérent avec
 * supplier_product_sourcing, qui ne porte elle-même aucune colonne
 * `activity` — cf. migration D1) — utilisable à l'identique quelle que
 * soit l'activité du produit courant (Sport, VTC, Bébé, Moto, Artisanat
 * du Maroc).
 *
 * Hors périmètre de D2.3 (délibérément absent de ce fichier) : aucune
 * mention ni logique "dropshipping", aucune sélection automatique de
 * fournisseur, aucune commande fournisseur automatique, aucune logique de
 * stock, aucune logique de marge — supplier_product_sourcing reste une
 * pure fiche de configuration déclarative, éditée manuellement ici.
 *
 * Actions volontairement limitées à Create/Edit/Delete : Associate/
 * Dissociate (pertinentes pour une relation HasMany vers un modèle
 * "libre", réutilisable indépendamment de son parent) n'ont pas de sens
 * ici — chaque fiche de supplier_product_sourcing appartient à exactement
 * un produit dès sa création (product_id non nullable, cascadeOnDelete,
 * cf. migration D1) : elle ne peut pas être "associée" depuis un pool de
 * fiches existantes détachées d'un autre produit.
 */
class SupplierSourcingsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierSourcings';

    protected static ?string $title = 'Sourcing fournisseurs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_id')
                    ->label('Fournisseur')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                // Restreint aux variantes du PRODUIT courant (celui sur
                // lequel ce RelationManager est monté) : product_id n'est
                // pas exposé dans ce formulaire (rempli automatiquement
                // par la relation supplierSourcings()), donc une variante
                // d'un autre produit ne serait pas seulement hors sujet
                // mais incohérente avec la fiche créée. Pur filtrage de
                // cohérence relationnelle, aucune règle métier nouvelle.
                Select::make('product_variant_id')
                    ->label('Variante (optionnel)')
                    ->relationship(
                        name: 'productVariant',
                        titleAttribute: 'sku',
                        modifyQueryUsing: fn ($query) => $query->where('product_id', $this->getOwnerRecord()->getKey()),
                    )
                    ->searchable()
                    ->preload(),

                TextInput::make('priority')
                    ->label('Priorité')
                    ->numeric()
                    ->default(100),

                TextInput::make('supplier_cost')
                    ->label('Coût fournisseur')
                    ->numeric(),

                TextInput::make('currency')
                    ->label('Devise')
                    ->maxLength(3),

                TextInput::make('lead_time_days')
                    ->label('Délai (jours)')
                    ->numeric(),

                TextInput::make('min_order_quantity')
                    ->label('Quantité minimale de commande')
                    ->numeric(),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable(),

                TextColumn::make('productVariant.sku')
                    ->label('Variante')
                    ->placeholder('—'),

                TextColumn::make('priority')
                    ->label('Priorité')
                    ->sortable(),

                TextColumn::make('supplier_cost')
                    ->label('Coût fournisseur')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('currency')
                    ->label('Devise'),

                TextColumn::make('lead_time_days')
                    ->label('Délai (j)'),

                TextColumn::make('min_order_quantity')
                    ->label('Qté min.'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
