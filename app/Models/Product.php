<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = [
        'photos' => 'array',
        'marketplaces' => 'array',
        'featured' => 'boolean',
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->categorie ??= $product->category()->value('name') ?? 'N/A';
            $product->marque ??= $product->brand()->value('name') ?? 'N/A';
            $product->fournisseur ??= $product->supplier()->value('name') ?? 'N/A';
            $product->equipe ??= $product->club()->value('name') ?? 'N/A';

            if (empty($product->taille)) {
                $product->taille = $product->variants()->value('size') ?? 'N/A';
            }
        });
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