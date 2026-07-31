<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('club_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('competition_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('slug')->nullable()->after('nom');
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable();
            $table->string('season')->nullable();

            $table->enum('version', [
                'Fan',
                'Player',
            ])->default('Fan');

            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            $table->boolean('featured')->default(false);
            $table->boolean('status')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->dropForeign(['brand_id']);
            $table->dropForeign(['category_id']);
            $table->dropForeign(['club_id']);
            $table->dropForeign(['competition_id']);
            $table->dropForeign(['supplier_id']);

            $table->dropColumn([
                'brand_id',
                'category_id',
                'club_id',
                'competition_id',
                'supplier_id',
                'slug',
                'sku',
                'barcode',
                'season',
                'version',
                'seo_title',
                'seo_description',
                'featured',
                'status',
            ]);
        });
    }
};
