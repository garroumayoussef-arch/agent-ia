<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 5.2 : logique métier de VtcRide (résolution fiscale, calculs
 * HT/TVA/TTC, gel après confirmation, obligation driver/vehicle à la
 * confirmation). Aucune Resource/API n'existe encore.
 */
class VtcRideTest extends TestCase
{
    use RefreshDatabase;

    private function makeDriver(array $attributes = []): Driver
    {
        return Driver::create(array_merge([
            'name' => 'Chauffeur Test',
        ], $attributes));
    }

    private function makeVehicle(array $attributes = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'plate_number' => 'AA-'.uniqid().'-ZZ',
        ], $attributes));
    }

    private function setVtcFiscalSetting(?TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate?->id],
        );
    }

    /*
     * =================================================================
     * Création en brouillon
     * =================================================================
     */

    public function test_une_course_peut_etre_creee_en_brouillon_sans_driver_ni_vehicle(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-1']);

        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertNull($ride->vehicle_id);
    }

    /*
     * =================================================================
     * Résolution fiscale et calcul HT/TVA/TTC
     * =================================================================
     */

    /**
     * Exemple obligatoire : 100 € HT + TVA 10 % = 10 € TVA = 110 € TTC.
     */
    public function test_vtc_taxable_a_10_pourcent_100ht_10tva_110ttc(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-2',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_TAXABLE, $ride->tax_status);
        $this->assertSame($rate10->id, $ride->tax_rate_id);
        $this->assertSame('10.00', $ride->tax_rate);
        $this->assertSame('100.00', $ride->total_ht);
        $this->assertSame('10.00', $ride->tax_amount);
        $this->assertSame('110.00', $ride->total_ttc);
        $this->assertNull($ride->legal_mention);

        $ride->markAsConfirmed();
        $this->assertSame(VtcRide::STATUS_CONFIRMED, $ride->fresh()->status);
    }

    public function test_vtc_exonere_a_un_tax_amount_a_zero_et_une_mention_legale(): void
    {
        $exempt = TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable, article 293 B du CGI',
        ]);
        $this->setVtcFiscalSetting($exempt);

        $ride = VtcRide::create([
            'reference' => 'VTC-3',
            'price_ht' => 100,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_EXEMPT, $ride->tax_status);
        $this->assertNull($ride->tax_rate);
        $this->assertSame('0.00', $ride->tax_amount);
        $this->assertSame('100.00', $ride->total_ht);
        $this->assertSame('100.00', $ride->total_ttc);
        $this->assertSame('TVA non applicable, article 293 B du CGI', $ride->legal_mention);
    }

    public function test_vtc_sans_regime_fiscal_configure_reste_non_resolu(): void
    {
        // FiscalSetting existe (comme après le seeder) mais pointe vers
        // NULL : aucun régime choisi.
        $this->setVtcFiscalSetting(null);

        $ride = VtcRide::create([
            'reference' => 'VTC-4',
            'price_ht' => 100,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_UNRESOLVED, $ride->tax_status);
        $this->assertNull($ride->tax_rate_id);
        $this->assertNull($ride->tax_rate);
        $this->assertNull($ride->tax_amount);
        $this->assertNull($ride->total_ttc);
        // Le HT, lui, reste connu : seule la partie fiscale est inconnue.
        $this->assertSame('100.00', $ride->total_ht);
    }

    public function test_vtc_sans_aucun_fiscal_setting_du_tout_reste_non_resolu(): void
    {
        // Aucune ligne fiscal_settings pour 'vtc' du tout (pas même une
        // avec tax_rate_id NULL) : le moteur ne doit pas planter et ne
        // doit jamais inventer un taux de repli.
        $ride = VtcRide::create([
            'reference' => 'VTC-5',
            'price_ht' => 100,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_UNRESOLVED, $ride->tax_status);
        $this->assertNull($ride->tax_amount);
    }

    public function test_le_taux_ne_retombe_jamais_sur_le_taux_par_defaut_marchandises(): void
    {
        // Un taux "marchandises" par défaut existe (is_default_sale),
        // mais aucune configuration fiscale VTC n'a été faite : la
        // course ne doit JAMAIS hériter du taux marchandises.
        TaxRate::create([
            'label' => 'Taux normal — marchandises',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_sale' => true,
        ]);

        $ride = VtcRide::create([
            'reference' => 'VTC-6',
            'price_ht' => 100,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_UNRESOLVED, $ride->tax_status);
        $this->assertNull($ride->tax_rate_id);
        $this->assertNull($ride->tax_amount);
    }

    public function test_une_remise_reduit_la_base_taxable(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-7',
            'price_ht' => 100,
            'discount_amount' => 20,
        ]);

        $this->assertSame('80.00', $ride->total_ht);
        $this->assertSame('8.00', $ride->tax_amount);
        $this->assertSame('88.00', $ride->total_ttc);
    }

    public function test_arrondi_monetaire_a_deux_decimales(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-8',
            'price_ht' => 33.33,
        ]);

        $this->assertSame('33.33', $ride->total_ht);
        $this->assertSame('3.33', $ride->tax_amount); // 33.33 * 10% = 3.333 -> arrondi 3.33
        $this->assertSame('36.66', $ride->total_ttc);
    }

    /*
     * =================================================================
     * Confirmation : driver/vehicle obligatoires, cohérence financière
     * =================================================================
     */

    public function test_confirmation_refusee_si_driver_manquant(): void
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-9',
            'price_ht' => 100,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->expectException(\Exception::class);
        $ride->markAsConfirmed();
    }

    public function test_confirmation_refusee_si_vehicle_manquant(): void
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-10',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
        ]);

        $this->expectException(\Exception::class);
        $ride->markAsConfirmed();
    }

    public function test_confirmation_refusee_si_prix_ht_manquant(): void
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-11',
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->expectException(\Exception::class);
        $ride->markAsConfirmed();
    }

    public function test_confirmation_refusee_si_taux_non_resolu(): void
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-12',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->assertSame(VtcRide::TAX_STATUS_UNRESOLVED, $ride->tax_status);

        $this->expectException(\Exception::class);
        $ride->markAsConfirmed();
    }

    /*
     * =================================================================
     * Immutabilité après confirmation
     * =================================================================
     */

    public function test_modifier_un_montant_financier_apres_confirmation_est_rejete(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-13',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->update(['price_ht' => 500]);
    }

    public function test_modifier_le_taux_dune_course_confirmee_est_rejete(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-14',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->update(['tax_amount' => 999]);
    }

    public function test_modifier_le_taux_de_reference_apres_confirmation_ne_change_pas_lhistorique(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-15',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        // Le taux de référence lui-même change APRÈS confirmation.
        $rate10->update(['rate' => 20]);

        $ride->refresh();
        $this->assertSame('10.00', $ride->tax_rate);
        $this->assertSame('10.00', $ride->tax_amount);
        $this->assertSame('110.00', $ride->total_ttc);
    }

    public function test_modifier_le_fiscal_setting_apres_confirmation_ne_change_pas_lhistorique(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $exempt = TaxRate::create(['label' => 'Franchise en base', 'type' => TaxRate::TYPE_EXEMPT]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-16',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        // Le régime fiscal de l'entreprise bascule APRÈS confirmation
        // (ex. passage en franchise en base).
        $this->setVtcFiscalSetting($exempt);

        $ride->refresh();
        $this->assertSame(VtcRide::TAX_STATUS_TAXABLE, $ride->tax_status);
        $this->assertSame('10.00', $ride->tax_amount);

        // Une NOUVELLE course, elle, reflète bien le nouveau régime.
        $newRide = VtcRide::create(['reference' => 'VTC-17', 'price_ht' => 100]);
        $this->assertSame(VtcRide::TAX_STATUS_EXEMPT, $newRide->tax_status);
        $this->assertSame('0.00', $newRide->tax_amount);
    }

    public function test_supprimer_une_course_confirmee_est_rejete(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-18',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->delete();
    }

    /*
     * =================================================================
     * Aucune interaction avec le stock
     * =================================================================
     */

    public function test_une_course_vtc_ninteragit_jamais_avec_stock_movement(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-19',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();
        $ride->update(['notes' => 'Course terminée sans encombre.']);

        $this->assertSame(0, StockMovement::count());
    }

    /*
     * =================================================================
     * confirmed_at (étape 5.6) — date de référence pour le reporting
     * =================================================================
     */

    public function test_confirmed_at_est_null_tant_que_la_course_est_en_brouillon(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-20', 'price_ht' => 100]);

        $this->assertNull($ride->confirmed_at);
    }

    public function test_markasconfirmed_fixe_confirmed_at(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-21',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->assertNull($ride->confirmed_at);

        $ride->markAsConfirmed();

        $this->assertNotNull($ride->fresh()->confirmed_at);
        $this->assertTrue($ride->fresh()->confirmed_at->isToday());
    }

    public function test_confirmed_at_ne_peut_plus_etre_modifie_une_fois_fixee(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-22',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->update(['confirmed_at' => now()->subDays(10)]);
    }

    /**
     * confirmed_at n'est pas dans la liste "montants figés" (elle y
     * causerait une auto-blocage lors de la confirmation elle-même,
     * cf. commentaire du modèle) : ce test vérifie explicitement que
     * les DEUX protections (liste générique + garde dédiée) coexistent
     * sans interférer l'une avec l'autre.
     */
    public function test_confirmer_ne_declenche_pas_le_verrou_generique_des_montants(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-23',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        // Ne doit lever aucune exception.
        $ride->markAsConfirmed();

        $this->assertSame(VtcRide::STATUS_CONFIRMED, $ride->status);
        $this->assertSame('10.00', $ride->tax_amount);
    }

    /*
     * =================================================================
     * Annulation (étape 5.7) — uniquement depuis brouillon, jamais
     * après confirmation
     * =================================================================
     */

    public function test_annuler_une_course_en_brouillon_passe_son_statut_a_cancelled(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-24', 'price_ht' => 100]);

        $ride->cancel();

        $this->assertSame(VtcRide::STATUS_CANCELLED, $ride->fresh()->status);
    }

    public function test_annuler_une_course_deja_confirmee_est_refuse(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-25',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->cancel();
    }

    public function test_le_statut_reste_confirmed_apres_une_tentative_dannulation_refusee(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-26',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        try {
            $ride->cancel();
        } catch (\Throwable $e) {
            // Attendu : cf. test précédent, on vérifie ici seulement
            // l'absence d'effet de bord sur le statut persisté.
        }

        $this->assertSame(VtcRide::STATUS_CONFIRMED, $ride->fresh()->status);
    }

    /**
     * Une fois annulée, une course est un historique figé au même
     * titre qu'une course confirmée (static::updating() ne distingue
     * pas les deux statuts non-brouillon) : ses montants restent
     * protégés par le même verrou générique.
     */
    public function test_les_montants_restent_proteges_apres_annulation(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-27', 'price_ht' => 100]);
        $ride->cancel();

        $this->expectException(\Exception::class);
        $ride->update(['price_ht' => 200]);
    }
}
