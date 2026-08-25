<?php

namespace Tests\Feature;

use App\Models\InvoiceSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T23 (D6) — numérotation légale : strictement unique, sans
 * doublon, protégée par verrouillage transactionnel
 * (InvoiceSequence::nextNumber()).
 */
class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_numeros_sont_strictement_croissants(): void
    {
        $this->assertSame('FA-2026-000001', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2026-000002', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2026-000003', InvoiceSequence::nextNumber(2026, 'FA'));
    }

    public function test_deux_annees_differentes_ont_des_compteurs_independants(): void
    {
        $this->assertSame('FA-2025-000001', InvoiceSequence::nextNumber(2025, 'FA'));
        $this->assertSame('FA-2026-000001', InvoiceSequence::nextNumber(2026, 'FA'));
        $this->assertSame('FA-2025-000002', InvoiceSequence::nextNumber(2025, 'FA'));
        $this->assertSame('FA-2026-000002', InvoiceSequence::nextNumber(2026, 'FA'));
    }

    public function test_le_prefixe_configure_est_respecte(): void
    {
        $this->assertSame('INV-2026-000001', InvoiceSequence::nextNumber(2026, 'INV'));
    }

    /**
     * insertOrIgnore() garantit qu'un second appel sur une année dont
     * la ligne existe déjà ne lève jamais d'exception (contrainte
     * unique sur `year`) — vérifie explicitement ce cas, pas seulement
     * la croissance des numéros.
     */
    public function test_un_appel_repete_sur_une_annee_deja_initialisee_ne_leve_aucune_exception(): void
    {
        InvoiceSequence::nextNumber(2026, 'FA');

        $this->assertCount(1, InvoiceSequence::where('year', 2026)->get());

        $second = InvoiceSequence::nextNumber(2026, 'FA');

        $this->assertSame('FA-2026-000002', $second);
        $this->assertCount(1, InvoiceSequence::where('year', 2026)->get());
    }
}
