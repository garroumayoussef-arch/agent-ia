<?php

namespace Tests\Feature;

use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T10 (fondation multi-entrepôts) : Warehouse est un modèle
 * volontairement autonome — aucune relation vers Product/
 * ProductVariant/StockMovement à ce stade (T11/T12). Ces tests portent
 * uniquement sur ce que T10 introduit réellement : l'entité elle-même
 * et sa garde "un seul défaut à la fois".
 */
class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_entrepot_peut_etre_cree(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Entrepôt Paris',
            'code' => 'paris',
        ]);

        // is_active a un défaut DB (true) : non reflété en mémoire tant
        // que le modèle n'est pas rechargé (même principe déjà
        // documenté ailleurs dans ce projet, ex. VtcRideModelsTest.php).
        $this->assertTrue($warehouse->fresh()->is_active);
        $this->assertFalse($warehouse->fresh()->is_default);
    }

    public function test_le_code_dun_entrepot_est_unique(): void
    {
        Warehouse::create(['name' => 'Entrepôt A', 'code' => 'dupliqué']);

        $this->expectException(QueryException::class);
        Warehouse::create(['name' => 'Entrepôt B', 'code' => 'dupliqué']);
    }

    /*
     * =================================================================
     * Garde "un seul entrepôt par défaut à la fois"
     * =================================================================
     */

    public function test_marquer_un_entrepot_par_defaut_a_la_creation_ne_leve_aucune_exception(): void
    {
        // Cas particulier vérifié explicitement : $warehouse->exists
        // est false à la création, ->id est encore null au moment de
        // saving() — la garde ne doit pas planter sur ce cas.
        $warehouse = Warehouse::create([
            'name' => 'Entrepôt Principal',
            'code' => 'principal',
            'is_default' => true,
        ]);

        $this->assertTrue($warehouse->fresh()->is_default);
    }

    public function test_activer_is_default_sur_un_nouvel_entrepot_desactive_lancien(): void
    {
        $ancien = Warehouse::create([
            'name' => 'Entrepôt A',
            'code' => 'a',
            'is_default' => true,
        ]);

        $nouveau = Warehouse::create([
            'name' => 'Entrepôt B',
            'code' => 'b',
            'is_default' => true,
        ]);

        $this->assertFalse($ancien->fresh()->is_default);
        $this->assertTrue($nouveau->fresh()->is_default);
    }

    public function test_activer_is_default_via_update_desactive_lancien(): void
    {
        $ancien = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a', 'is_default' => true]);
        $nouveau = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);

        $nouveau->update(['is_default' => true]);

        $this->assertFalse($ancien->fresh()->is_default);
        $this->assertTrue($nouveau->fresh()->is_default);
    }

    public function test_desactiver_is_default_ne_promeut_aucun_autre_entrepot(): void
    {
        $entrepot = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a', 'is_default' => true]);
        Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);

        $entrepot->update(['is_default' => false]);

        $this->assertFalse($entrepot->fresh()->is_default);
        $this->assertSame(0, Warehouse::where('is_default', true)->count());
    }

    public function test_resauvegarder_un_entrepot_par_defaut_sans_changer_is_default_ne_perturbe_rien(): void
    {
        $entrepot = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a', 'is_default' => true]);

        // isDirty('is_default') est false ici : la garde ne doit rien
        // toucher (elle ne doit pas se redéclencher inutilement).
        $entrepot->update(['name' => 'Entrepôt A renommé']);

        $this->assertTrue($entrepot->fresh()->is_default);
    }
}
