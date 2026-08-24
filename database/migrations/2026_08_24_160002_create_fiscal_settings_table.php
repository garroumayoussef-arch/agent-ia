<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Représente le régime fiscal RÉELLEMENT appliqué par l'entreprise
     * pour une activité donnée (ex. 'vtc') — à distinguer du taux de
     * référence lui-même (qui vit dans tax_rates, ex. 10 % transport de
     * voyageurs). Ce sont deux informations différentes : le taux de
     * référence ne change pas si Magarrou passe de la franchise en
     * base au régime réel, seule cette ligne change (elle repointe
     * vers un autre tax_rates.id).
     *
     * `activity` est une chaîne libre (pas un enum) pour rester
     * extensible à d'autres activités futures sans migration.
     *
     * tax_rate_id nullable ET SANS valeur seedée par défaut pour 'vtc'
     * (cf. seeder) : on ne présume ni régime réel ni franchise en base
     * tant qu'un administrateur ne l'a pas configuré explicitement.
     */
    public function up(): void
    {
        Schema::create('fiscal_settings', function (Blueprint $table) {
            $table->id();

            $table->string('activity')->unique();
            $table->string('label')->nullable();

            $table->foreignId('tax_rate_id')
                ->nullable()
                ->constrained('tax_rates')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_settings');
    }
};
