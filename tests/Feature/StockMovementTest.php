<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-' . uniqid(),
            'nom' => 'Maillot Test',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-' . uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_un_produit_peut_etre_cree_avec_les_nouvelles_relations_sans_champs_legacy(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $category = Category::create(['name' => 'Football', 'slug' => 'football']);
        $club = Club::create(['name' => 'Paris Saint-Germain', 'slug' => 'paris-saint-germain']);
        $competition = Competition::create(['name' => 'Ligue 1', 'slug' => 'ligue-1']);
        $supplier = Supplier::create(['name' => 'AliExpress']);

        $product = Product::create([
            'reference' => 'REF-NEW',
            'nom' => 'Maillot PSG',
            'type' => 'Player Version',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'club_id' => $club->id,
            'competition_id' => $competition->id,
            'supplier_id' => $supplier->id,
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $this->assertNotNull($product->id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Football', $product->categorie);
        $this->assertSame('Nike', $product->marque);
        $this->assertSame('AliExpress', $product->fournisseur);
        $this->assertSame('N/A', $product->taille);
    }

    public function test_un_achat_sur_variante_met_a_jour_le_stock_de_la_variante_et_du_produit(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(15, $variant->stock);
        $this->assertSame(15, $product->stock);
    }

    public function test_le_stock_produit_reste_synchronise_quand_une_variante_est_editee_directement(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 3]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 4]);

        // Édition directe (hors StockMovement), comme via le Repeater du formulaire Produit.
        $variantA->update(['stock' => 10]);

        $product->refresh();

        $this->assertSame(14, $product->stock); // 10 + 4
    }

    public function test_une_vente_en_stock_insuffisant_est_rejetee_sans_ecriture_partielle(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 2]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'sale',
                'quantity' => 5,
            ]);
        } finally {
            $variant->refresh();
            $product->refresh();

            // Aucune écriture partielle : stock inchangé (2, synchronisé dès la création de la variante).
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);

            // Aucune ligne "fantôme" créée dans stock_movements.
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_ajustement_peut_legalement_ramener_le_stock_a_zero(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 8]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'adjustment',
            'quantity' => 0,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(0, $variant->stock);
        $this->assertSame(0, $product->stock);
    }

    public function test_un_mouvement_de_type_transfert_est_rejete_sans_ecriture(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'transfer',
                'quantity' => 1,
            ]);
        } finally {
            $variant->refresh();

            $this->assertSame(5, $variant->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_les_champs_json_du_produit_sont_bien_castes_en_tableau(): void
    {
        $product = $this->makeProduct([
            'photos' => ['photo1.jpg', 'photo2.jpg'],
            'marketplaces' => ['amazon', 'ebay'],
            'featured' => true,
            'status' => true,
        ]);

        $product->refresh();

        $this->assertIsArray($product->photos);
        $this->assertSame(['photo1.jpg', 'photo2.jpg'], $product->photos);
        $this->assertIsArray($product->marketplaces);
        $this->assertSame(['amazon', 'ebay'], $product->marketplaces);
        $this->assertIsBool($product->featured);
        $this->assertTrue($product->featured);
        $this->assertIsBool($product->status);
        $this->assertTrue($product->status);
    }

    /*
     * =================================================================
     * D.1 — product_variant_id réellement optionnel
     * =================================================================
     */

    public function test_un_mouvement_peut_etre_cree_sur_un_produit_sans_aucune_variante(): void
    {
        $product = $this->makeProduct(['stock' => 5]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $product->refresh();

        $this->assertSame(15, $product->stock);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_un_produit_avec_variantes_refuse_un_mouvement_sans_variante_selectionnee(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'purchase',
                'quantity' => 10,
            ]);
        } finally {
            $product->refresh();

            // Aucune écriture partielle : le stock (déjà synchronisé
            // à 5 par la variante) ne doit pas bouger.
            $this->assertSame(5, $product->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    /*
     * =================================================================
     * D.2 — Modification d'un mouvement existant
     * =================================================================
     */

    public function test_modifier_la_quantite_dun_mouvement_recalcule_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $variant->refresh();
        $this->assertSame(15, $variant->stock);

        $movement->update(['quantity' => 20]);

        $movement->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(5, $movement->stock_before);
        $this->assertSame(25, $movement->stock_after);
        $this->assertSame(25, $variant->stock);
        $this->assertSame(25, $product->stock);
    }

    public function test_modifier_un_mouvement_recalcule_en_cascade_les_mouvements_suivants(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $vente = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);

        $variant->refresh();
        $this->assertSame(6, $variant->stock); // 10 - 4

        // On corrige l'achat initial : 10 -> 20.
        $achat->update(['quantity' => 20]);

        $achat->refresh();
        $vente->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(0, $achat->stock_before);
        $this->assertSame(20, $achat->stock_after);

        // La vente qui suit doit être recalculée en cascade.
        $this->assertSame(20, $vente->stock_before);
        $this->assertSame(16, $vente->stock_after); // 20 - 4

        $this->assertSame(16, $variant->stock);
        $this->assertSame(16, $product->stock);
    }

    public function test_modifier_un_mouvement_est_rejete_si_cela_rendrait_le_stock_negatif_plus_loin_dans_lhistorique(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 8,
        ]);

        $variant->refresh();
        $this->assertSame(2, $variant->stock); // 10 - 8

        $this->expectException(\Exception::class);

        try {
            // Si l'achat initial passait de 10 à 5, la vente de 8 qui
            // suit deviendrait impossible (5 - 8 < 0).
            $achat->update(['quantity' => 5]);
        } finally {
            $achat->refresh();
            $variant->refresh();
            $product->refresh();

            // Aucune écriture partielle : tout reste inchangé.
            $this->assertSame(10, $achat->quantity);
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);
        }
    }

    public function test_on_ne_peut_pas_changer_le_produit_ou_la_variante_dun_mouvement_existant(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 5]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variantA->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->expectException(\Exception::class);

        try {
            $movement->update(['product_variant_id' => $variantB->id]);
        } finally {
            $movement->refresh();
            $this->assertSame($variantA->id, $movement->product_variant_id);
        }
    }

    public function test_modifier_un_champ_sans_impact_sur_le_stock_ne_recalcule_rien(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $movement->update(['notes' => 'Commentaire ajouté après coup']);

        $movement->refresh();
        $variant->refresh();

        $this->assertSame('Commentaire ajouté après coup', $movement->notes);
        $this->assertSame(5, $movement->stock_before);
        $this->assertSame(15, $movement->stock_after);
        $this->assertSame(15, $variant->stock);
    }

    /*
     * =================================================================
     * D.2 — Suppression d'un mouvement existant
     * =================================================================
     */

    public function test_supprimer_un_mouvement_retablit_correctement_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat1 = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $achat2 = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $variant->refresh();
        $this->assertSame(15, $variant->stock);

        $achat1->delete();

        $achat2->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, $achat2->stock_before);
        $this->assertSame(5, $achat2->stock_after);
        $this->assertSame(5, $variant->stock);
        $this->assertSame(5, $product->stock);
    }

    public function test_supprimer_un_mouvement_est_rejete_si_cela_rendrait_le_stock_negatif(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 8,
        ]);

        $variant->refresh();
        $this->assertSame(2, $variant->stock);

        $this->expectException(\Exception::class);

        try {
            $achat->delete();
        } finally {
            $variant->refresh();
            $product->refresh();

            $this->assertSame(2, StockMovement::count());
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);
        }
    }

    public function test_supprimer_lunique_mouvement_dun_produit_sans_variante_retablit_le_stock_initial(): void
    {
        $product = $this->makeProduct(['stock' => 3]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 7,
        ]);

        $product->refresh();
        $this->assertSame(10, $product->stock);

        $movement->delete();

        $product->refresh();
        $this->assertSame(3, $product->stock);
        $this->assertSame(0, StockMovement::count());
    }

    /*
     * =================================================================
     * user_id renseigné automatiquement à la création
     * =================================================================
     */

    public function test_le_user_id_est_renseigne_automatiquement_avec_lutilisateur_authentifie(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->actingAs($user);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->assertSame($user->id, $movement->user_id);
    }

    public function test_le_user_id_reste_null_sans_utilisateur_authentifie(): void
    {
        // Aucun `actingAs()` : simule un contexte système/CLI/job sans
        // utilisateur connecté. La création ne doit pas être bloquée.
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->assertNull($movement->user_id);
        $variant->refresh();
        $this->assertSame(15, $variant->stock);
    }

    public function test_un_user_id_fourni_explicitement_nest_pas_ecrase(): void
    {
        $loggedInUser = User::factory()->create();
        $attributedTo = User::factory()->create();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->actingAs($loggedInUser);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
            'user_id' => $attributedTo->id,
        ]);

        $this->assertSame($attributedTo->id, $movement->user_id);
    }
}
