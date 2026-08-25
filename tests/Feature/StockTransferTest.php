<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T12 — StockTransfer::execute() est le point d'entrée unique et
 * atomique pour déplacer du stock entre deux entrepôts, sans jamais
 * modifier le stock global (Product.stock/ProductVariant.stock).
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Produit T12',
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
            'sku' => 'SKU-'.uniqid(),
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    private function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt '.uniqid(),
            'code' => 'w-'.uniqid(),
        ], $attributes));
    }

    /*
     * =================================================================
     * Transfert réussi
     * =================================================================
     */

    public function test_un_transfert_diminue_le_stock_source_et_augmente_le_stock_destination(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);

        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 0]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 4,
        ]);

        $stockA = WarehouseStock::where('warehouse_id', $warehouseA->id)->where('product_variant_id', $variant->id)->value('stock');
        $stockB = WarehouseStock::where('warehouse_id', $warehouseB->id)->where('product_variant_id', $variant->id)->value('stock');

        $this->assertSame(6, $stockA);
        $this->assertSame(4, $stockB);
    }

    public function test_le_stock_global_reste_inchange_apres_un_transfert(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);

        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 10]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 4,
        ]);

        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_un_transfert_cree_deux_stock_movement_lies_par_stock_transfer_id(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 10]);

        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $movements = StockMovement::where('stock_transfer_id', $transfer->id)->orderBy('id')->get();

        $this->assertCount(2, $movements);
        $this->assertSame('transfer_out', $movements[0]->type);
        $this->assertSame($warehouseA->id, $movements[0]->warehouse_id);
        $this->assertSame('transfer_in', $movements[1]->type);
        $this->assertSame($warehouseB->id, $movements[1]->warehouse_id);
        $this->assertSame(4, $movements[0]->quantity);
        $this->assertSame(4, $movements[1]->quantity);
    }

    public function test_un_transfert_fonctionne_sur_un_produit_sans_variante(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 6]);

        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 6]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->assertSame(6, $product->fresh()->stock);
        $this->assertSame(4, WarehouseStock::where('warehouse_id', $warehouseA->id)->where('product_id', $product->id)->whereNull('product_variant_id')->value('stock'));
        $this->assertSame(2, WarehouseStock::where('warehouse_id', $warehouseB->id)->where('product_id', $product->id)->whereNull('product_variant_id')->value('stock'));
    }

    public function test_lutilisateur_authentifie_est_renseigne_automatiquement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->assertSame($user->id, $transfer->user_id);
        $this->assertSame($user->id, $transfer->stockMovements()->first()->user_id);
    }

    /*
     * =================================================================
     * Refus / validations
     * =================================================================
     */

    public function test_un_transfert_est_refuse_si_le_stock_est_insuffisant_a_la_source_meme_si_le_stock_global_est_suffisant_ailleurs(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 13]); // global suffisant

        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 3]); // source insuffisante
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 10]);

        $this->expectException(\Exception::class);

        try {
            StockTransfer::execute([
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 5, // > 3 (source) mais < 13 (global)
            ]);
        } finally {
            $this->assertSame(0, StockTransfer::count());
            $this->assertSame(0, StockMovement::count());
            $this->assertSame(13, $variant->fresh()->stock);
            $this->assertSame(3, WarehouseStock::where('warehouse_id', $warehouseA->id)->value('stock'));
            $this->assertSame(10, WarehouseStock::where('warehouse_id', $warehouseB->id)->value('stock'));
        }
    }

    public function test_un_transfert_est_refuse_si_source_egale_destination(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockTransfer::execute([
                'from_warehouse_id' => $warehouse->id,
                'to_warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
            ]);
        } finally {
            $this->assertSame(0, StockTransfer::count());
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_transfert_est_refuse_si_quantite_nulle(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockTransfer::execute([
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'product_id' => $product->id,
                'quantity' => 0,
            ]);
        } finally {
            $this->assertSame(0, StockTransfer::count());
        }
    }

    public function test_un_transfert_est_refuse_si_quantite_negative(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockTransfer::execute([
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'product_id' => $product->id,
                'quantity' => -3,
            ]);
        } finally {
            $this->assertSame(0, StockTransfer::count());
        }
    }

    /*
     * =================================================================
     * Indivisibilité (immuabilité des mouvements liés à un transfert)
     * =================================================================
     */

    public function test_on_ne_peut_pas_modifier_un_mouvement_appartenant_a_un_transfert(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $movement = $transfer->stockMovements()->first();

        $this->expectException(\Exception::class);

        try {
            $movement->update(['notes' => 'tentative de modification']);
        } finally {
            $movement->refresh();
            $this->assertNotSame('tentative de modification', $movement->notes);
        }
    }

    public function test_on_ne_peut_pas_supprimer_un_mouvement_appartenant_a_un_transfert(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $movement = $transfer->stockMovements()->first();

        $this->expectException(\Exception::class);

        try {
            $movement->delete();
        } finally {
            $this->assertSame(2, StockMovement::where('stock_transfer_id', $transfer->id)->count());
        }
    }

    public function test_un_transfert_ne_peut_pas_etre_modifie(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->expectException(\Exception::class);

        $transfer->update(['reference' => 'modifie']);
    }

    public function test_un_transfert_ayant_des_mouvements_ne_peut_pas_etre_supprime(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->expectException(\Exception::class);

        try {
            $transfer->delete();
        } finally {
            $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id]);
        }
    }

    /*
     * =================================================================
     * Protection de suppression d'un Warehouse référencé
     * =================================================================
     */

    public function test_un_entrepot_source_dun_transfert_ne_peut_pas_etre_supprime(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->expectException(\Exception::class);

        try {
            $warehouseA->delete();
        } finally {
            $this->assertDatabaseHas('warehouses', ['id' => $warehouseA->id]);
        }
    }

    public function test_un_entrepot_destination_dun_transfert_ne_peut_pas_etre_supprime(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 5]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->expectException(\Exception::class);

        try {
            $warehouseB->delete();
        } finally {
            $this->assertDatabaseHas('warehouses', ['id' => $warehouseB->id]);
        }
    }
}
