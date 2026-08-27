<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier "facturation légale VTC" (D1-D11, validés) —
 * Invoice::generateFromVtcRide(). Miroir direct d'InvoiceGenerationTest
 * (T23), avec les mêmes garanties (SIREN B2B, régime TVA jamais
 * supposé, immuabilité, numérotation), plus les points spécifiques à
 * ce chantier : statut confirmed strict, anti-doublon, ligne unique
 * (D3), avoir réutilisé sans modification (D11), reporting non faussé
 * (D10 — testé séparément dans CommercialOverviewTest).
 */
class VtcRideInvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function configureCompanySettings(array $overrides = []): CompanySettings
    {
        $settings = CompanySettings::current();
        $settings->update(array_merge([
            'legal_name' => 'Magarrou',
            'legal_form' => 'SASU',
            'address' => '1 rue du Sport',
            'postal_code' => '75000',
            'city' => 'Paris',
            'country' => 'France',
            'siren' => '111222333',
            'siret' => '11122233300010',
            'rcs_city' => 'Paris',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
            'vat_number' => 'FR11111222333',
            'recovery_indemnity_amount' => 40,
            'vtc_invoice_number_prefix' => 'FV',
        ], $overrides));

        return $settings->fresh();
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Jean Dupont',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => '2 avenue des Clients',
            'postal_code' => '69000',
            'city' => 'Lyon',
            'country' => 'France',
        ], $attributes));
    }

    private function makeDriver(): Driver
    {
        return Driver::create(['name' => 'Chauffeur Test', 'is_active' => true]);
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true]);
    }

    /**
     * Crée une course, la confirme (TVA 10% déjà résolue via
     * FiscalSetting), avec un client donné.
     */
    private function makeConfirmedRide(Customer $customer, float $priceHt = 100): VtcRide
    {
        FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => TaxRate::firstOrCreate(
                ['label' => 'VTC 10%'],
                ['type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true],
            )->id],
        );

        $ride = VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => $customer->id,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
            'price_ht' => $priceHt,
        ]);

        $ride->markAsConfirmed();

        return $ride->fresh();
    }

    /*
     * =================================================================
     * D5 — statut confirmed strictement requis
     * =================================================================
     */

    public function test_la_generation_est_refusee_pour_une_course_en_brouillon(): void
    {
        $this->configureCompanySettings();
        $ride = VtcRide::create([
            'reference' => 'VTC-DRAFT',
            'customer_id' => $this->makeCustomer()->id,
            'price_ht' => 100,
        ]);

        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->status);

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromVtcRide($ride);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    public function test_la_generation_reussit_pour_une_course_confirmee(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());

        $invoice = Invoice::generateFromVtcRide($ride);

        $this->assertSame(1, Invoice::count());
        $this->assertSame($ride->id, $invoice->vtc_ride_id);
        $this->assertNull($invoice->sales_order_id);
        $this->assertSame('prestation', $invoice->operation_category);
    }

    /*
     * =================================================================
     * D5 — anti-doublon (une seule facture par course)
     * =================================================================
     */

    public function test_une_seule_facture_par_course(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());

        Invoice::generateFromVtcRide($ride);

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromVtcRide($ride->fresh());
        } finally {
            $this->assertSame(1, Invoice::count());
        }
    }

    /**
     * Preuve directe que le rempart de base (contrainte UNIQUE sur
     * vtc_ride_id) est bien celui qui protège, pas seulement le contrôle
     * applicatif : une tentative d'insertion directe en base d'une
     * seconde facture pour la même course doit être rejetée par la
     * contrainte, indépendamment de generateFromVtcRide().
     */
    public function test_la_contrainte_unique_en_base_empeche_une_seconde_facture_pour_la_meme_course(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());
        Invoice::generateFromVtcRide($ride);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Invoice::query()->create([
            'vtc_ride_id' => $ride->id,
            'number' => 'FV-2026-999999',
            'issued_at' => now()->toDateString(),
            'sale_completed_at' => now()->toDateString(),
            'transaction_type' => 'b2c_domestic',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'customer_name' => 'Test',
            'seller_legal_name' => 'Magarrou',
            'seller_address' => 'x',
            'seller_postal_code' => 'x',
            'seller_city' => 'x',
            'seller_country' => 'France',
            'seller_siren' => '111222333',
            'vat_regime_snapshot' => CompanySettings::VAT_REGIME_STANDARD,
            'recovery_indemnity_amount_snapshot' => 40,
            'total_ht' => 100,
            'discount_amount' => 0,
            'status' => Invoice::STATUS_ISSUED,
        ]);
    }

    /**
     * D5 (validé) — démonstration de concurrence RÉELLE (processus OS
     * indépendants, pas des threads PHP simulés) : N appels strictement
     * simultanés de generateFromVtcRide() sur LA MÊME course ne doivent
     * jamais produire plus d'une seule facture. Même méthodologie que
     * InvoiceNumberSequenceTest::test_douze_processus_concurrents_...()
     * (T25-A), appliquée ici à la garde anti-doublon (niveaux 1-3 de
     * Invoice::generateFromVtcRide()) plutôt qu'à la numérotation.
     */
    public function test_plusieurs_processus_concurrents_ne_produisent_jamais_deux_factures_pour_la_meme_course(): void
    {
        $scratchDir = sys_get_temp_dir().'/vtc_invoice_concurrency_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        $resultsFile = $scratchDir.'/results.txt';
        $setupFile = $scratchDir.'/setup.php';
        $probeFile = $scratchDir.'/probe.php';
        touch($dbFile);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();

        // Migre le schéma complet (toutes les tables nécessaires :
        // vtc_rides, customers, drivers, vehicles, tax_rates,
        // fiscal_settings, company_settings, invoices, invoice_lines,
        // invoice_sequences) dans cette base isolée.
        $migrateProcess = proc_open(
            ['php', 'artisan', 'migrate', '--database=sqlite', '--force'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $basePath,
            $envOverrides,
        );
        $migrateOutput = stream_get_contents($migratePipes[1]).stream_get_contents($migratePipes[2]);
        $migrateStatus = proc_close($migrateProcess);
        $this->assertSame(0, $migrateStatus, 'La migration de la base isolée a échoué : '.$migrateOutput);

        // Prépare les données (entreprise, TVA VTC, client, chauffeur,
        // véhicule, course CONFIRMÉE) une seule fois, dans un processus
        // dédié, AVANT toute concurrence — écrit l'ID de la course dans
        // un fichier pour que les processus concurrents la partagent.
        file_put_contents($setupFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \App\Models\CompanySettings::current()->update([
                'legal_name' => 'Magarrou', 'legal_form' => 'SASU', 'address' => '1 rue',
                'postal_code' => '75000', 'city' => 'Paris', 'country' => 'France',
                'siren' => '111222333', 'vat_regime' => \App\Models\CompanySettings::VAT_REGIME_STANDARD,
                'vat_number' => 'FR1', 'recovery_indemnity_amount' => 40,
                'vtc_invoice_number_prefix' => 'FV',
            ]);
            \$rate = \App\Models\TaxRate::create(['label' => 'VTC 10%', 'type' => \App\Models\TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
            \App\Models\FiscalSetting::create(['activity' => \App\Models\FiscalSetting::ACTIVITY_VTC, 'tax_rate_id' => \$rate->id]);
            \$customer = \App\Models\Customer::create(['name' => 'Client', 'customer_type' => 'individual', 'address' => 'x', 'city' => 'x', 'country' => 'France']);
            \$driver = \App\Models\Driver::create(['name' => 'D', 'is_active' => true]);
            \$vehicle = \App\Models\Vehicle::create(['plate_number' => 'AA-1-ZZ', 'is_active' => true]);
            \$ride = \App\Models\VtcRide::create(['reference' => 'VTC-CONC', 'customer_id' => \$customer->id, 'driver_id' => \$driver->id, 'vehicle_id' => \$vehicle->id, 'price_ht' => 100]);
            \$ride->markAsConfirmed();

            echo \$ride->fresh()->id;
            PHP);

        $setupProcess = proc_open(
            ['php', $setupFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $setupPipes,
            $basePath,
            $envOverrides,
        );
        $rideId = trim(stream_get_contents($setupPipes[1]));
        $setupError = stream_get_contents($setupPipes[2]);
        $setupStatus = proc_close($setupProcess);
        $this->assertSame(0, $setupStatus, 'La préparation de la base isolée a échoué : '.$setupError);
        $this->assertNotSame('', $rideId, 'Aucun ID de course renvoyé par le setup : '.$setupError);

        // Chaque processus tente UNE fois de facturer la MÊME course, et
        // rapporte "OK" ou "REJECTED" (jamais une exception technique
        // brute — cf. Invoice::generateFromVtcRide(), qui traduit
        // systématiquement en message métier).
        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$ride = \App\Models\VtcRide::find((int) \$argv[2]);

            try {
                \App\Models\Invoice::generateFromVtcRide(\$ride);
                \$result = 'OK';
            } catch (\Throwable \$e) {
                \$result = 'REJECTED';
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, \$result."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);

        $processCount = 10;
        $handles = [];
        $allPipes = [];

        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                ['php', $probeFile, $resultsFile, $rideId],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $envOverrides,
            );
            $allPipes[$i] = $pipes;
        }

        foreach ($handles as $i => $handle) {
            stream_get_contents($allPipes[$i][1]);
            stream_get_contents($allPipes[$i][2]);
            proc_close($handle);
        }

        $results = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));
        $this->assertCount($processCount, $results, 'Nombre de tentatives inattendu.');

        $okCount = count(array_filter($results, fn (string $r): bool => $r === 'OK'));
        $rejectedCount = count(array_filter($results, fn (string $r): bool => $r === 'REJECTED'));

        // Le résultat impératif de ce test : EXACTEMENT une réussite,
        // toutes les autres rejetées proprement (jamais un doublon,
        // jamais une exception technique non gérée).
        $this->assertSame(1, $okCount, 'Exactement une génération aurait dû réussir.');
        $this->assertSame($processCount - 1, $rejectedCount, 'Toutes les autres tentatives auraient dû être rejetées proprement.');

        // Vérification finale directement en base : une seule facture,
        // quelle que soit la vue de chaque processus individuel.
        $pdo = new \PDO('sqlite:'.$dbFile);
        $count = $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $this->assertSame('1', (string) $count);
    }

    /*
     * =================================================================
     * D6 — client obligatoire et SIREN B2B
     * =================================================================
     */

    public function test_la_generation_est_refusee_sans_client(): void
    {
        $this->configureCompanySettings();
        FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true])->id],
        );
        $ride = VtcRide::create([
            'reference' => 'VTC-SANS-CLIENT',
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromVtcRide($ride->fresh());
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    public function test_generation_b2b_est_refusee_sans_siren(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer(['customer_type' => Customer::TYPE_BUSINESS, 'siren' => null]);
        $ride = $this->makeConfirmedRide($customer);

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromVtcRide($ride);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    /**
     * D6 (validé) — confirme empiriquement que customer_id reste
     * éditable après confirmation (n'est PAS dans la liste des champs
     * gelés par VtcRide::updating(), non modifiée par ce chantier) :
     * un client peut donc être renseigné a posteriori sur une course
     * déjà confirmée, avant de la facturer.
     */
    public function test_le_client_peut_etre_renseigne_apres_confirmation_puis_permettre_la_facturation(): void
    {
        $this->configureCompanySettings();
        FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true])->id],
        );
        $ride = VtcRide::create([
            'reference' => 'VTC-CLIENT-APRES',
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();
        $ride->refresh();
        $this->assertNull($ride->customer_id);

        $customer = $this->makeCustomer();
        $ride->update(['customer_id' => $customer->id]);

        $invoice = Invoice::generateFromVtcRide($ride->fresh());
        $this->assertSame($customer->id, $invoice->customer_id);
    }

    public function test_generation_b2b_reussit_avec_siren(): void
    {
        $this->configureCompanySettings(['country' => 'France']);
        $customer = $this->makeCustomer([
            'customer_type' => Customer::TYPE_BUSINESS,
            'siren' => '987654321',
            'country' => 'France',
        ]);
        $ride = $this->makeConfirmedRide($customer);

        $invoice = Invoice::generateFromVtcRide($ride);

        $this->assertSame('987654321', $invoice->customer_siren);
        $this->assertSame('b2b_domestic', $invoice->transaction_type);
    }

    /*
     * =================================================================
     * D5 — régime TVA jamais supposé (CompanySettings)
     * =================================================================
     */

    public function test_la_generation_est_refusee_si_lentreprise_nest_pas_configuree(): void
    {
        // Aucun configureCompanySettings() : CompanySettings::current()
        // reste entièrement vide.
        $ride = $this->makeConfirmedRide($this->makeCustomer());

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromVtcRide($ride);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    /*
     * =================================================================
     * D2 — numérotation sur série dédiée
     * =================================================================
     */

    public function test_les_numeros_de_facture_vtc_utilisent_le_prefixe_dedie(): void
    {
        $this->configureCompanySettings(['vtc_invoice_number_prefix' => 'FV']);
        $ride = $this->makeConfirmedRide($this->makeCustomer());

        $invoice = Invoice::generateFromVtcRide($ride);

        $this->assertStringStartsWith('FV-', $invoice->number);
    }

    /**
     * D2 (validé) — les deux séries (vente 'FA' / VTC 'FV') ne
     * partagent jamais leur compteur, même la même année civile.
     */
    public function test_la_serie_vtc_est_independante_de_la_serie_vente(): void
    {
        $this->configureCompanySettings(['vtc_invoice_number_prefix' => 'FV', 'invoice_number_prefix' => 'FA']);

        $product = \App\Models\Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        \App\Models\Warehouse::create(['name' => 'Entrepôt', 'code' => 'defaut', 'is_default' => true]);
        TaxRate::create(['label' => 'TVA 20%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20, 'is_default_sale' => true, 'is_active' => true]);

        $customer = $this->makeCustomer();
        $order = \App\Models\SalesOrder::create(['reference' => 'CMD-1', 'customer_id' => $customer->id]);
        $item = \App\Models\SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 20,
        ]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 1]);

        $salesInvoice = Invoice::generateFromSalesOrder($order->fresh());
        $vtcInvoice = Invoice::generateFromVtcRide($this->makeConfirmedRide($customer));

        $this->assertStringStartsWith('FA-2026-000001', $salesInvoice->number);
        $this->assertStringStartsWith('FV-2026-000001', $vtcInvoice->number);
    }

    /*
     * =================================================================
     * D3 — ligne unique
     * =================================================================
     */

    public function test_la_facture_vtc_produit_une_ligne_unique_sans_produit(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer(), priceHt: 150);

        $invoice = Invoice::generateFromVtcRide($ride);

        $this->assertSame(1, $invoice->lines()->count());
        $line = $invoice->lines()->first();
        $this->assertNull($line->product_id);
        $this->assertSame(1, $line->quantity);
        $this->assertEqualsWithDelta(150.0, (float) $line->unit_price_ht, 0.001);
        $this->assertEqualsWithDelta((float) $ride->total_ttc, (float) $line->total_ttc, 0.001);
    }

    /*
     * =================================================================
     * D4 — libellé d'origine
     * =================================================================
     */

    public function test_origin_label_distingue_les_deux_origines(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());
        $invoice = Invoice::generateFromVtcRide($ride);

        $this->assertSame("Course VTC {$ride->reference}", $invoice->originLabel());
    }

    /*
     * =================================================================
     * Immuabilité — héritée automatiquement d'Invoice::booted(), déjà
     * couverte pour l'origine SalesOrder par InvoiceGenerationTest ;
     * preuve directe ici que ça s'applique aussi à une facture VTC.
     * =================================================================
     */

    public function test_une_facture_vtc_emise_ne_peut_pas_etre_modifiee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromVtcRide($this->makeConfirmedRide($this->makeCustomer()));

        $this->expectException(\Exception::class);
        $invoice->update(['total_ttc' => 999]);
    }

    public function test_une_facture_vtc_emise_ne_peut_pas_etre_supprimee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromVtcRide($this->makeConfirmedRide($this->makeCustomer()));

        $this->expectException(\Exception::class);
        $invoice->delete();
    }

    /**
     * La course sous-jacente reste elle aussi figée (VtcRide::updating())
     * une fois confirmée : une facture VTC ne peut donc jamais devenir
     * incohérente avec sa course d'origine après émission.
     */
    public function test_la_course_source_ne_peut_plus_etre_modifiee_apres_facturation(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());
        Invoice::generateFromVtcRide($ride);

        $this->expectException(\Exception::class);
        $ride->update(['price_ht' => 999]);
    }

    /*
     * =================================================================
     * D11 — avoir réutilisé sans modification, seule voie de correction
     * =================================================================
     */

    public function test_un_avoir_peut_etre_genere_sur_une_facture_vtc_sans_modification_de_creditnote(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromVtcRide($this->makeConfirmedRide($this->makeCustomer(), priceHt: 100));

        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice(
            $invoice,
            $lineIds,
            'Course annulée après facturation',
            CreditNote::SETTLEMENT_REFUND,
        );

        $this->assertSame(1, CreditNote::count());
        $this->assertEqualsWithDelta((float) $invoice->total_ttc, (float) $creditNote->total_ttc, 0.001);
        $this->assertSame(Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, $invoice->fresh()->paymentStatus());
    }

    /**
     * Confirme qu'il n'existe aucune autre voie de correction qu'un
     * avoir : ni la course (confirmée, figée), ni la facture (immuable)
     * ne peuvent être modifiées ou supprimées après émission (déjà
     * prouvé ci-dessus) — l'avoir est donc la SEULE issue, exactement
     * comme pour une facture de vente.
     */
    public function test_une_course_confirmee_et_facturee_ne_peut_pas_etre_annulee(): void
    {
        $this->configureCompanySettings();
        $ride = $this->makeConfirmedRide($this->makeCustomer());
        Invoice::generateFromVtcRide($ride);

        $this->expectException(\Exception::class);
        $ride->fresh()->cancel();
    }

    /*
     * =================================================================
     * D9 — accès chauffeur (statu quo, BlocksChauffeurReadAccess non
     * modifié) : même blocage qu'une facture de vente, indépendamment de
     * l'origine de la facture (le mécanisme n'inspecte jamais les
     * colonnes sales_order_id/vtc_ride_id, cf. InvoiceResourceTest pour
     * la couverture générale déjà existante au niveau de la liste).
     * =================================================================
     */

    public function test_un_chauffeur_ne_peut_pas_consulter_une_facture_vtc_par_url_directe(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromVtcRide($this->makeConfirmedRide($this->makeCustomer()));

        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Sans Accès Facture', 'user_id' => $user->id]);
        $this->actingAs($user);

        // BlocksChauffeurReadAccess ne filtre pas getEloquentQuery() (à la
        // différence de VtcRideResource) : l'enregistrement est trouvé,
        // mais canView() le refuse -> 403, pas 404 (cf.
        // InvoiceResourceTest::test_un_chauffeur_ne_peut_pas_consulter_les_factures()
        // pour la même assertion sur la liste).
        $this->get(InvoiceResource::getUrl('view', ['record' => $invoice]))->assertForbidden();
    }
}
