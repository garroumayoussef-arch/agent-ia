<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Driver = identité métier du chauffeur VTC, indépendante et
     * volontairement légère : ce n'est PAS un second système
     * d'identité qui ferait doublon avec User (qui reste le compte de
     * connexion à l'ERP). user_id est nullable — un chauffeur n'a pas
     * forcément de compte système — et unique : un même compte User ne
     * peut être lié qu'à un seul Driver.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('license_number')->nullable();
            $table->string('phone')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
