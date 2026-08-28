<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "Notifications & communication" V1 (D8/D9, validés) —
 * journal UNIQUE servant à la fois d'anti-duplication (D8) et d'audit
 * (D9) des envois externes (email client/fournisseur) et des digests
 * internes (stock bas). Une seule table pour les deux besoins,
 * volontairement, pour ne jamais faire diverger deux infrastructures
 * parallèles.
 *
 * Contrainte UNIQUE sur (notifiable_type, notifiable_id, event_type,
 * occurrence_key) — même défense en profondeur que celle déjà validée
 * sur invoices.vtc_ride_id (chantier VTC, D5/D8) : réservation AVANT
 * toute tentative d'envoi (cf. NotificationLog::reserve()), rempart
 * final contre tout doublon même en cas d'appel concurrent ou répété.
 *
 * occurrence_key : 'once' pour les événements à tir unique (facture,
 * avoir, retour client, retour fournisseur — le document source
 * lui-même n'existe qu'une fois, immuable) ; date du jour pour le
 * digest quotidien de stock bas (autorise un envoi par jour, jamais
 * plus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            $table->string('event_type');
            $table->string('channel');
            $table->string('recipient_address')->nullable();
            $table->string('occurrence_key')->default('once');

            $table->string('status');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();

            $table->foreignId('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['notifiable_type', 'notifiable_id', 'event_type', 'occurrence_key'],
                'notification_logs_unique_event',
            );
            $table->index(['notifiable_type', 'notifiable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
