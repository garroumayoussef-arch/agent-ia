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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->string('company')->nullable();

            $table->string('email')->nullable();

            $table->string('phone')->nullable();

            $table->string('whatsapp')->nullable();

            $table->string('website')->nullable();

            $table->string('country')->nullable();

            $table->string('currency')->default('EUR');

            $table->enum('supplier_type', [
                'manufacturer',
                'dropshipping',
                'stock'
            ])->default('stock');

            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
