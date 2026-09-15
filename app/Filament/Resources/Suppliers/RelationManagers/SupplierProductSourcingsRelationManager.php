<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Filament\Concerns\DeterminesMutationAccessByRole;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Chantier Dropshipping, étape D2.4.2 — interface Filament du sourcing
 * fournisseur → produit, côté SupplierResource. Symétrique de
 * SupplierSourcingsRelationManager (étape D2.3, côté ProductResource) :
 * s'appuie EXCLUSIVEMENT sur la relation Eloquent déjà existante
 * Supplier::productSourcings() (étape D1) : aucune nouvelle relation,
 * aucune requête reconstruite. Caractérisé par
 * tests/Feature/SupplierProductSourcingsRelationManagerTest.php (D2.4.1),
 * écrit avant ce fichier.
 *
 * Volontairement neutre vis-à-vis des activités : aucun filtre, aucune
 * condition sur `activity` nulle part dans ce fichier (cohérent avec
 * supplier_product_sourcing, qui ne porte elle-même aucune colonne
 * `activity` — cf. migration D1) — utilisable à l'identique quelle que
 * soit l'activité du produit sourcé (Sport, VTC, Bébé, Moto, Artisanat
 * du Maroc, Dropshipping).
 *
 * Hors périmètre de D2.4.2 (délibérément absent de ce fichier) : aucune
 * mention ni logique "dropshipping", aucune sélection automatique de
 * fournisseur, aucune commande fournisseur automatique, aucune logique de
 * stock, aucune logique de marge, aucun appel API — supplier_product_sourcing
 * reste une pure fiche de configuration déclarative, éditée manuellement
 * ici.
 *
 * Étape D2.4.4 — create/edit/delete/deleteAny restreints à admin/manager,
 * via les 4 overrides de get*AuthorizationResponse() ci-dessous (seuls
 * points réellement consultés par Filament pour autoriser les actions du
 * tableau — cf. RelationManager::getDefaultActionAuthorizationResponse() :
 * canCreate()/canEdit()/canDelete()/canDeleteAny(), hérités inchangés de
 * InteractsWithRelationshipTable, ne sont que des enveloppes bool qui
 * délèguent à ces mêmes méthodes, donc corrigés du même coup). Règle de
 * rôle réutilisée depuis DeterminesMutationAccessByRole (même logique
 * que HasRoleBasedAuthorization, jamais dupliquée) : aucune Policy
 * Laravel introduite, aucune nouvelle règle métier. deleteAny n'a aucun
 * effet observable ici : aucune DeleteBulkAction dans ce RelationManager
 * (cf. table() ci-dessous), non ajoutée par cette étape.
 *
 * Différence structurelle assumée avec le RelationManager côté Product :
 * là où SupplierSourcingsRelationManager filtre product_variant_id sur le
 * PRODUIT propriétaire (fixe, déterminé par $this->getOwnerRecord()), ici
 * le propriétaire est le FOURNISSEUR — product_id est donc un champ du
 * formulaire (obligatoire, jamais fixé par la relation), et
 * product_variant_id doit être filtré dynamiquement sur la valeur de
 * product_id CHOISIE dans ce même formulaire, jamais sur le fournisseur
 * (une variante appartient à un produit, pas à un fournisseur). Même
 * mécanique Filament (Get/Set + ->live()) que StockMovementForm pour un
 * couple product_id/product_variant_id dépendant.
 *
 * Actions volontairement limitées à Create/Edit/Delete : Associate/
 * Dissociate n'ont pas de sens ici, pour la même raison que côté Product
 * — chaque fiche de supplier_product_sourcing appartient à exactement un
 * fournisseur dès sa création (supplier_id non nullable, cf. migration
 * D1) : elle ne peut pas être "associée" depuis un pool de fiches
 * existantes détachées d'un autre fournisseur.
 */
class SupplierProductSourcingsRelationManager extends RelationManager
{
    use DeterminesMutationAccessByRole;

    protected static string $relationship = 'productSourcings';

    protected static ?string $title = 'Sourcing produits';

    protected function getCreateAuthorizationResponse(): Response
    {
        return static::currentUserCanMutate() ? Response::allow() : Response::deny();
    }

    protected function getEditAuthorizationResponse(Model $record): Response
    {
        return static::currentUserCanMutate() ? Response::allow() : Response::deny();
    }

    protected function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::currentUserCanMutate() ? Response::allow() : Response::deny();
    }

    protected function getDeleteAnyAuthorizationResponse(): Response
    {
        return static::currentUserCanMutate() ? Response::allow() : Response::deny();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Jamais supplier_id ici : déterminé automatiquement par
                // la relation productSourcings() du Supplier propriétaire.
                Select::make('product_id')
                    ->label('Produit')
                    ->relationship('product', 'nom')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('product_variant_id', null);
                    })
                    ->required(),

                // Filtré dynamiquement sur le product_id CHOISI ci-dessus
                // (jamais sur le fournisseur courant) : une variante
                // appartient au produit, pas au fournisseur.
                Select::make('product_variant_id')
                    ->label('Variante (optionnel)')
                    ->relationship(
                        name: 'productVariant',
                        titleAttribute: 'sku',
                        modifyQueryUsing: fn ($query, Get $get) => $query->where('product_id', $get('product_id')),
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
                TextColumn::make('product.nom')
                    ->label('Produit')
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
