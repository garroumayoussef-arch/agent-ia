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

            /*
             * Tier 2, étape 2.6.1 — la dérivation depuis une variante ne
             * s'applique que si `attribute_definitions` déclare "taille"
             * comme un attribut de niveau produit APPLICABLE à l'activité
             * de CE produit (transverse `activity=null`, ou scopé à cette
             * activité précise) : aucune activité n'est nommée en dur
             * ici, contrairement au comportement d'origine qui dérivait
             * inconditionnellement `taille` pour n'importe quelle
             * activité. Sans définition applicable, `products.taille`
             * (colonne NOT NULL, migration d'origine) reçoit directement
             * 'N/A' — même repli qu'avant, sans consulter les variantes
             * d'un produit pour lequel ce concept n'a aucun sens.
             */
            if (empty($product->taille)) {
                $product->taille = static::productAttributeDefinitionApplies($product, 'taille')
                    ? ($product->variants()->value('size') ?? 'N/A')
                    : 'N/A';
            }
        });

        /*
         * Tier 2 (préparation), étape 2.2 — dual-write (miroir) vers le
         * système d'attributs génériques créé au Tier 1. Les colonnes
         * dédiées `season`, `taille`, `equipe` restent l'UNIQUE source
         * de vérité (jamais lues depuis la table miroir, jamais
         * écrasées ici) : on se contente de recopier leur valeur telle
         * quelle dans `product_attribute_values` à chaque sauvegarde,
         * afin que l'étape 2.3 (backfill du catalogue existant) puis
         * 2.4 (vérification croisée) aient une base à jour pour les
         * produits créés/modifiés entre-temps.
         *
         * `version` est volontairement ABSENTE de cette liste : audit
         * dédié (résolution du conflit "version") ayant établi que
         * `products.version` n'est exposée dans aucun formulaire
         * Filament, lue par aucun code applicatif, et peuplée
         * uniquement par ProductFactory à des fins de test — la migrer
         * n'aurait aucune valeur et perpétuerait l'ambiguïté avec
         * `product_variants.version` (seule réellement utilisée).
         */
        static::saved(function (self $product): void {
            static::syncAttributeMirror($product, 'season', $product->season);
            static::syncAttributeMirror($product, 'taille', $product->taille);
            static::syncAttributeMirror($product, 'equipe', $product->equipe);
        });

        /*
         * Un produit ayant un historique de mouvements de stock, ou
         * référencé dans un bon de commande fournisseur / une commande
         * client, ne doit jamais être supprimé : contrairement à
         * ProductVariant (FK en nullOnDelete), les tables stock_movements,
         * purchase_order_items et sales_order_items sont en
         * cascadeOnDelete sur product_id — une suppression détruirait
         * silencieusement tout cet historique (audit stock, lignes de
         * commandes déjà (partiellement) réceptionnées/expédiées).
         * Même logique de protection que PurchaseOrder::deleting() /
         * SalesOrder::deleting(), appliquée un cran plus bas.
         */
        static::deleting(function (self $product): void {
            if ($product->stockMovements()->exists()) {
                throw new \Exception(
                    "Impossible de supprimer ce produit : il possède un historique de mouvements de stock. Désactivez-le plutôt que de le supprimer."
                );
            }

            if ($product->purchaseOrderItems()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer ce produit : il est référencé dans au moins un bon de commande fournisseur.'
                );
            }

            if ($product->salesOrderItems()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer ce produit : il est référencé dans au moins une commande client.'
                );
            }
        });

        /*
         * Miroir "fournisseur" stale après suppression d'un Supplier :
         * supplier_id est en nullOnDelete (cf. migration
         * update_products_table), donc lors de la suppression d'un
         * Supplier, la contrainte FK met directement supplier_id à NULL
         * en base pour tous ses produits — sans jamais passer par
         * Eloquent. Le hook Product::saving() ci-dessus (seul point qui
         * recalcule le champ texte `fournisseur`) ne se déclenche donc
         * jamais pour ces lignes, qui gardent l'ancien nom du fournisseur
         * indéfiniment.
         *
         * On se branche ici sur l'événement `deleting` du Supplier —
         * AVANT que la contrainte FK ne mette supplier_id à NULL — afin
         * de pouvoir encore retrouver les produits concernés par
         * supplier_id, et remettre leur miroir à 'N/A' en une seule
         * requête. Rien n'est modifié dans Supplier lui-même : ce
         * listener vit entièrement du côté du modèle qui possède le
         * miroir à maintenir.
         */
        Supplier::deleting(function (Supplier $supplier): void {
            static::where('supplier_id', $supplier->id)->update(['fournisseur' => 'N/A']);
        });

        /*
         * Même mécanisme, même cause, pour les miroirs "marque" et
         * "categorie" : brand_id / category_id sont également en
         * nullOnDelete sur products (cf. update_products_table), donc
         * supprimer une Brand ou une Category les contourne exactement
         * comme pour Supplier ci-dessus.
         */
        Brand::deleting(function (Brand $brand): void {
            static::where('brand_id', $brand->id)->update(['marque' => 'N/A']);
        });

        Category::deleting(function (Category $category): void {
            static::where('category_id', $category->id)->update(['categorie' => 'N/A']);
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
     * Tier 2, étape 2.6.1 — vérifie si un attribut de niveau produit
     * (`code`) est APPLICABLE à l'activité de ce produit, d'après
     * `attribute_definitions` (Tier 1). Ne code en dur aucun nom
     * d'activité : une définition `activity=null` s'applique à toute
     * activité (transverse), une définition `activity='<x>'` ne
     * s'applique qu'aux produits de cette activité précise. Si aucune
     * définition `level='product'` portant ce `code` n'existe encore
     * (ex. seed non exécuté dans cet environnement), l'attribut est
     * considéré non applicable — même garantie best-effort que
     * `syncAttributeMirror()` ci-dessous, dont ce contrôle reprend
     * exactement le même principe de portée par activité.
     */
    private static function productAttributeDefinitionApplies(self $product, string $code): bool
    {
        $definition = AttributeDefinition::where('code', $code)
            ->where('level', 'product')
            ->first();

        if (! $definition) {
            return false;
        }

        if ($definition->activity === null) {
            return true;
        }

        return $definition->activity === $product->activity;
    }

    /**
     * Tier 2 (préparation), étape 2.2 — recopie la valeur BRUTE d'une
     * colonne dédiée dans `product_attribute_values`, sans aucune
     * transformation. Ignore silencieusement (aucune exception) si :
     * - la valeur est NULL (rien à recopier) ;
     * - la définition d'attribut correspondant à ce `code` n'existe pas
     *   encore (ex. seed de l'étape 2.1 non exécuté dans cet
     *   environnement) ;
     * - la définition est scopée à une autre activité que celle de ce
     *   produit.
     * Dans tous ces cas, la sauvegarde du produit continue normalement :
     * ce miroir est un effet secondaire best-effort, jamais une
     * condition bloquante pour l'écriture des colonnes existantes.
     */
    private static function syncAttributeMirror(self $product, string $code, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $definition = AttributeDefinition::where('code', $code)->first();

        if (! $definition) {
            return;
        }

        /*
         * `products.activity` n'est jamais NULL en base (colonne NOT
         * NULL, défaut SQL posé au Tier 1, étape 1/6) : aucune activité
         * — Sport, Bébé, Moto, VTC, Artisanat — n'a de statut par
         * défaut privilégié dans ce code, ce défaut SQL est une donnée
         * de schéma, pas une préférence applicative. Si l'attribut est
         * NULL ici, c'est uniquement qu'Eloquent n'a pas encore
         * resynchronisé l'objet en mémoire avec la ligne insérée (le
         * défaut SQL n'est jamais rejoué côté PHP après un create()).
         * On relit alors la valeur RÉELLEMENT persistée pour CE produit
         * précis — jamais une valeur supposée ou codée en dur.
         */
        $productActivity = $product->activity ?? self::whereKey($product->id)->value('activity');

        if ($definition->activity !== null && $definition->activity !== $productActivity) {
            return;
        }

        ProductAttributeValue::updateOrCreate(
            [
                'product_id' => $product->id,
                'attribute_definition_id' => $definition->id,
            ],
            ['value' => $value]
        );
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
     * Taux de TVA par défaut de ce produit à l'achat. Distinct de
     * saleTaxRate() : un même produit peut avoir un taux différent à
     * l'achat et à la vente.
     */
    public function purchaseTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'purchase_tax_rate_id');
    }

    /**
     * Taux de TVA par défaut de ce produit à la vente.
     */
    public function saleTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'sale_tax_rate_id');
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

    /**
     * Relation avec les lignes de bons de commande fournisseur
     * référençant ce produit.
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Relation avec les lignes de commande client référençant ce produit.
     */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /**
     * Valeurs d'attributs génériques de niveau produit (Tier 1, étape
     * 6/6) — table `product_attribute_values` créée à l'étape 4/6.
     * Purement additif : Sport continue de fonctionner exclusivement
     * via ses colonnes dédiées (club_id, competition_id, equipe,
     * taille, season, version), inchangées.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Tier 2 (préparation), étape 2.4 — lecture PARALLÈLE depuis le
     * système d'attributs génériques, pour un `code` donné (ex.
     * 'season'). N'est jamais lue par aucun autre code de
     * l'application : sert uniquement à comparer, dans les tests, la
     * valeur miroir à la colonne dédiée correspondante — aucune
     * bascule, aucun remplacement. Retourne `null` si aucune ligne
     * miroir n'existe pour ce produit et ce `code` (attribut jamais
     * écrit, ou définition inexistante).
     */
    public function attributeMirrorValue(string $code): ?string
    {
        return $this->attributeValues()
            ->whereHas('attributeDefinition', fn ($query) => $query->where('code', $code))
            ->value('value');
    }

    /**
     * Chantier Dropshipping, étape D1 — fiches de sourcing déclarées pour
     * ce produit (quels fournisseurs peuvent le fournir). Relation
     * additive en lecture seule : ne crée aucune nouvelle écriture sur
     * Product, capacité Core réutilisable par n'importe quelle activité
     * (aucune dépendance à `activity`).
     */
    public function supplierSourcings(): HasMany
    {
        return $this->hasMany(SupplierProductSourcing::class);
    }
}