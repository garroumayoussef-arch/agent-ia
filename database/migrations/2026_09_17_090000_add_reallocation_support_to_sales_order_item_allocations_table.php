<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier Dropshipping, étape D2.9 — support de la ré-allocation
 * manuelle vers un fournisseur alternatif, après retour intégral au
 * fournisseur initial (spécification validée). Purement additive au
 * niveau des données existantes : aucune ligne déjà présente n'est
 * modifiée, la nouvelle colonne reçoit NULL pour toutes.
 *
 * ============================================================
 * CONTRAINTE REMPLACÉE : UNIQUE(sales_order_item_id)
 * ============================================================
 * D2.4.7 posait UNIQUE(sales_order_item_id) : au plus une allocation par
 * ligne de commande, split multi-fournisseur et ré-allocation
 * explicitement hors périmètre à l'époque. D2.9 exige au contraire de
 * CONSERVER l'ancienne allocation (immuable, jamais supprimée) tout en
 * créant une nouvelle allocation additive pour la MÊME ligne — ce que
 * l'ancienne contrainte interdit structurellement. Elle est donc
 * supprimée et remplacée par un index simple (la colonne reste
 * consultée par recordFor()/reallocateFor() et par
 * SalesOrderItem::allocation(), désormais un hasOne()->latestOfMany()).
 *
 * SalesOrderItemAllocation::recordFor() n'est pas modifiée : sa garde
 * applicative `exists()` sur sales_order_item_id (portant sur TOUTE
 * allocation, historique ou active) continue seule de garantir qu'il ne
 * peut jamais exister deux chaînes indépendantes ("racines") pour la
 * même ligne — l'ancienne contrainte UNIQUE n'était qu'un filet
 * redondant avec cette garde, jamais sa seule protection.
 *
 * ============================================================
 * NOUVELLE COLONNE : replaces_allocation_id
 * ============================================================
 * Auto-référence vers sales_order_item_allocations.id, portée
 * UNIQUEMENT par la NOUVELLE ligne (celle qui remplace) — jamais écrite
 * sur l'ancienne. C'est ce qui garantit l'immutabilité de l'historique
 * au niveau du schéma : le lien est toujours porté en amont par le
 * remplaçant, jamais en aval par le remplacé.
 *
 * - nullable : NULL pour toute allocation "racine" (créée par
 *   recordFor()) — donc pour la totalité des lignes déjà existantes.
 *   Non-NULL uniquement pour une allocation née de reallocateFor().
 * - unique : garde DB DÉFINITIVE contre le remplacement multiple d'une
 *   même allocation historique — au plus une ré-allocation par
 *   allocation remplacée, jamais uniquement applicatif (même
 *   philosophie que purchase_order_items.sales_order_item_allocation_id,
 *   D2.6.2). Tolère nativement plusieurs NULL (norme SQL standard,
 *   identique SQLite/PostgreSQL).
 * - restrictOnDelete : une allocation déjà remplacée ne peut jamais
 *   disparaître silencieusement (même convention que
 *   sales_order_item_id ci-dessus et que purchase_order_items.
 *   sales_order_item_allocation_id, D2.6.2). CASCADE est exclu
 *   (supprimerait l'historique en chaîne) ; SET NULL est exclu
 *   (romprait silencieusement la traçabilité remplaçant/remplacé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_item_allocations', function (Blueprint $table) {
            $table->dropUnique('sales_order_item_allocations_sales_order_item_id_unique');
            $table->index('sales_order_item_id');

            $table->foreignId('replaces_allocation_id')
                ->nullable()
                ->unique()
                ->after('sales_order_item_id')
                ->constrained('sales_order_item_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_item_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_allocation_id');
            $table->dropIndex(['sales_order_item_id']);
            $table->unique('sales_order_item_id');
        });
    }
};
