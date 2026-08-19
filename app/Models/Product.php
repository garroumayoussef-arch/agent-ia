<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'photos' => 'array',
        'marketplaces' => 'array',
        'featured' => 'boolean',
        'status' => 'boolean',
    ];

    /**
     * Seuil en dessous (ou à hauteur) duquel un stock est considéré
     * "bas". Utilisé à la fois pour la coloration des badges de stock
     * (ProductsTable, ProductVariantsTable) et pour les alertes stock
     * bas (badges de navigation, widget LowStockAlert) : un seul point
     * de vérité pour ne pas laisser deux endroits diverger.
     */
    public const LOW_STOCK_THRESHOLD = 5;

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            /*
             * categorie / marque / fournisseur sont de purs "miroirs" de
             * category_id / brand_id / supplier_id : aucun champ du
             * formulaire Produit ne permet de les éditer manuellement,
             * la relation est donc la SEULE source de vérité. On les
             * recalcule à CHAQUE sauvegarde (pas uniquement à la
             * création) pour éviter qu'ils restent figés après un
             * changement de marque/catégorie/fournisseur.
             *
             * Si la relation est absente ET n'a jamais été renseignée
             * (aucun *_id historique), on conserve la valeur existante
             * afin de ne pas écraser d'éventuelles données legacy
             * saisies avant l'introduction des relations. Si la relation
             * vient d'être explicitement retirée (*_id passé à null),
             * le champ miroir est remis à 'N/A' plutôt que de garder une
             * ancienne valeur périmée.
             */
            static::syncMirroredRelationField($product, 'categorie', 'category_id', 'category');
            static::syncMirroredRelationField($product, 'marque', 'brand_id', 'brand');
            static::syncMirroredRelationField($product, 'fournisseur', 'supplier_id', 'supplier');

            /*
             * equipe / taille restent des champs éditables manuellement
             * dans le formulaire (TextInput dédié) : on ne les renseigne
             * que s'ils sont vides, sans jamais écraser une saisie
             * volontaire de l'administrateur.
             */
            $product->equipe ??= $product->club()->value('name') ?? 'N/A';

            if (empty($product->taille)) {
                $product->taille = $product->variants()->value('size') ?? 'N/A';
            }
        });
    }

    /**
     * Synchronise un champ texte "miroir" d'une relation BelongsTo
     * (categorie <-> category_id, marque <-> brand_id, fournisseur <->
     * supplier_id) : source de vérité = la relation quand elle existe.
     */
    private static function syncMirroredRelationField(
        self $product,
        string $field,
        string $foreignKey,
        string $relation,
    ): void {
        if ($product->{$foreignKey}) {
            $product->{$field} = $product->{$relation}()->value('name') ?? 'N/A';

            return;
        }

        if ($product->isDirty($foreignKey)) {
            // La relation vient d'être explicitement retirée.
            $product->{$field} = 'N/A';

            return;
        }

        // Aucune relation n'a jamais été définie : valeur legacy conservée.
        $product->{$field} ??= 'N/A';
    }

    /**
     * Relation avec la marque.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Relation avec la catégorie.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relation avec le club.
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Relation avec la compétition.
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * Relation avec le fournisseur.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Relation avec les variantes du produit.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Relation avec les mouvements de stock.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}