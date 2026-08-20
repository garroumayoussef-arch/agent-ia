<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        DB::table('product_variants')
            ->where('status', '1')
            ->update(['status' => 'active']);

        DB::table('product_variants')
            ->where('status', '0')
            ->update(['status' => 'inactive']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('product_variants')
            ->where('status', 'active')
            ->update(['status' => 1]);

        DB::table('product_variants')
            ->where('status', 'inactive')
            ->update(['status' => 0]);

        DB::table('product_variants')
            ->where('status', 'out_of_stock')
            ->update(['status' => 0]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('status')->default(true)->change();
        });
    }
};