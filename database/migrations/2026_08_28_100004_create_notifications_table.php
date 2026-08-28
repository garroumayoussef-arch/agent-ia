<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "Notifications & communication" V1 (D3, validé) — table
 * STANDARD du canal `database` natif de Laravel (Illuminate\Notifications),
 * jamais une invention de ce projet : elle alimente la cloche de
 * notifications interne du panel Filament (->databaseNotifications(),
 * cf. AdminPanelProvider) pour les alertes admin/manager (stock bas).
 * Schéma identique au stub officiel `notifications:table` de Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
