<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
        'prix_achat' => 'decimal:2',
        'prix_vente' => 'decimal:2',
    ];

    /**
     * Recalcule le stock du produit parent à partir de la somme
     * des stocks de toutes ses variantes.
     *
     * Point d'entrée UNIQUE de synchronisation Product.stock <-> variantes :
     * déclenché quel que soit le chemin d'écriture (mouvement de stock
     * via StockMovement, ou édition directe d'une variante dans le
     * formulaire Produit).
     */
    protected static function booted(): void
    {
        static::saved(function (ProductVariant $variant) {
            if ($variant->wasRecentlyCreated || $variant->wasChanged('stock')) {
                $variant->syncProductStock();
            }

            /*
             * Tier 2 (préparation), étape 2.2 — dual-write (miroir) vers
             * le système d'attributs génériques créé au Tier 1. Mêmes
             * garanties que le miroir équivalent sur Product::saved() :
             * `size`, `color`, `version` restent l'UNIQUE source de
             * vérité, jamais réécrites depuis la table miroir.
             */
            static::syncAttributeMirror($variant, 'size', $variant->size);
            static::syncAttributeMirror($variant, 'color', $variant->color);
            static::syncAttributeMirror($variant, 'version', $variant->version);
        });

        /*
         * Une variante ayant un historique de mouvements de stock ou
         * apparaissant dans un bon de commande fournisseur / une commande
         * client ne doit jamais être supprimée : contrairement à
         * Product.stockMovements (cascadeOnDelete), les FK ici sont en
         * nullOnDelete, donc une suppression ne détruirait pas ces lignes
         * mais leur ferait perdre la trace de la variante (taille/couleur)
         * réellement vendue ou achetée. Même logique de protection que
         * PurchaseOrderItem::deleting() / SalesOrderItem::deleting().
         */
        static::deleting(function (ProductVariant $variant) {
            if ($variant->stockMovements()->exists()) {
                throw new \Exception(
                    "Impossible de supprimer cette variante : elle possède un historique de mouvements de stock. Passez-la plutôt en rupture de stock ou inactive."
                );
            }

            if ($variant->purchaseOrderItems()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer cette variante : elle est référencée dans au moins un bon de commande fournisseur.'
                );
            }

            if ($variant->salesOrderItems()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer cette variante : elle est référencée dans au moins une commande client.'
                );
            }
        });

        static::deleted(function (ProductVariant $variant) {
            $variant->syncProductStock();
        });
    }

    /**
     * Met à jour Product.stock avec la somme des stocks de ses variantes.
     */
    protected function syncProductStock(): void
    {
        if (! $this->product_id) {
            return;
        }

        $total = static::where('product_id', $this->product_id)->sum('stock');

        Product::whereKey($this->product_id)->update(['stock' => $total]);
    }

    /**
     * Tier 2 (préparation), étape 2.2 — recopie la valeur BRUTE d'une
     * colonne dédiée dans `product_variant_attribute_values`, sans
     * aucune transformation. Ignore silencieusement (aucune exception)
     * si la valeur est NULL, si la définition d'attribut n'existe pas
     * encore, ou si elle est scopée à une autre activité que celle du
     * produit parent — mêmes garanties best-effort que
     * Product::syncAttributeMirror().
     */
    private static function syncAttributeMirror(self $variant, string $code, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $definition = AttributeDefinition::where('code', $code)->first();

        if (! $definition) {
            return;
        }

        $productActivity = $variant->product?->activity;

        if ($definition->activity !== null && $definition->activity !== $productActivity) {
            return;
        }

        ProductVariantAttributeValue::updateOrCreate(
            [
                'product_variant_id' => $variant->id,
                'attribute_definition_id' => $definition->id,
            ],
            ['value' => $value]
        );
    }

    /**
     * Relation avec le produit parent.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relation avec les mouvements de stock de cette variante.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class,
            'product_variant_id'
        );
    }

    /**
     * Relation avec les lignes de bons de commande fournisseur
     * référençant cette variante.
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(
            PurchaseOrderItem::class,
            'product_variant_id'
        );
    }

    /**
     * Relation avec les lignes de commande client
     * référençant cette variante.
     */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(
            SalesOrderItem::class,
            'product_variant_id'
        );
    }

    /**
     * Valeurs d'attributs génériques de niveau variante (Tier 1, étape
     * 6/6) — table `product_variant_attribute_values` créée à l'étape
     * 5/6. Purement additif : Sport continue de fonctionner
     * exclusivement via ses colonnes dédiées (size, color, version),
     * inchangées.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(
            ProductVariantAttributeValue::class,
            'product_variant_id'
        );
    }

    /**
     * Tier 2 (préparation), étape 2.4 — lecture PARALLÈLE depuis le
     * système d'attributs génériques, pour un `code` donné (ex.
     * 'size'). Même garantie que Product::attributeMirrorValue() :
     * jamais lue par aucun autre code de l'application, aucune
     * bascule, aucun remplacement — sert uniquement à la comparaison
     * de non-régression dans les tests.
     */
    public function attributeMirrorValue(string $code): ?string
    {
        return $this->attributeValues()
            ->whereHas('attributeDefinition', fn ($query) => $query->where('code', $code))
            ->value('value');
    }

    /**
     * Chantier Dropshipping, étape D1 — fiches de sourcing déclarées pour
     * cette variante (quels fournisseurs peuvent la fournir). Relation
     * additive en lecture seule, même convention que
     * purchaseOrderItems()/salesOrderItems() ci-dessus (FK explicite).
     */
    public function supplierSourcings(): HasMany
    {
        return $this->hasMany(
            SupplierProductSourcing::class,
            'product_variant_id'
        );
    }
}