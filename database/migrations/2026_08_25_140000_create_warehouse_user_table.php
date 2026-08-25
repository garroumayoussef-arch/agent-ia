<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T19 — rattachement d'un utilisateur à un ou plusieurs entrepôts
 * (pivot many-to-many, décision D7 : jamais une simple colonne
 * warehouse_id unique sur users, un manager pouvant couvrir plusieurs
 * entrepôts).
 *
 * cascadeOnDelete des deux côtés — contrairement aux protections
 * applicatives posées sur warehouse_stocks/stock_transfers (T11a/T12),
 * ce pivot ne doit JAMAIS empêcher la suppression d'un utilisateur ou
 * d'un entrepôt : ce n'est qu'une association d'autorisation, pas un
 * historique à préserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_user');
    }
};
