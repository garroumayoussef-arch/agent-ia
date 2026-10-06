<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definitions', function (Blueprint $table): void {
            $table->enum('applicability_mode', ['activity', 'category'])->default('activity');
        });

        Schema::create('category_attribute_definition', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('attribute_definition_id')->constrained('attribute_definitions')->restrictOnDelete();
            $table->boolean('include_descendants')->default(false);
            $table->timestamps();
            $table->unique(['category_id', 'attribute_definition_id'], 'category_attribute_definition_pair_unique');
            $table->index('attribute_definition_id', 'category_attribute_definition_definition_index');
        });
    }

    public function down(): void
    {
        // Retirer la portée pourrait réélargir des définitions déjà utilisées.
        throw new RuntimeException('Retrait du schéma d’applicabilité interdit sans procédure séparée autorisée.');
    }
};
