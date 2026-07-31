<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->unique();
            $table->string('nom');
            $table->string('categorie');
            $table->string('type');
            $table->string('marque')->nullable();
            $table->string('equipe')->nullable();
            $table->string('taille');
            $table->integer('stock')->default(0);
            $table->decimal('prix_achat', 8, 2);
            $table->decimal('prix_vente', 8, 2);
            $table->string('fournisseur')->nullable();
            $table->json('photos')->nullable();
            $table->json('marketplaces')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};