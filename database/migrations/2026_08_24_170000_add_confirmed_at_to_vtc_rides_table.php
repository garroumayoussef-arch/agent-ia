<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Date de référence pour tout reporting/statistique par période
     * (étape 5.6) : ni `performed_at` (non exigé à la confirmation,
     * peut être NULL) ni `updated_at` (mouvant : les champs non
     * financiers restent modifiables après confirmation, cf. étape
     * 5.2) ne conviennent. `confirmed_at` est fixée une seule fois par
     * VtcRide::markAsConfirmed() et n'est plus jamais modifiable
     * ensuite (cf. VtcRide::updating()).
     */
    public function up(): void
    {
        Schema::table('vtc_rides', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vtc_rides', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
