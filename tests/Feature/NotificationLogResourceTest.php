<?php

namespace Tests\Feature;

use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D10, validé) — le
 * journal des notifications est réservé à l'admin, y compris en
 * lecture (même principe que UserResource, jamais
 * HasRoleBasedAuthorization qui ouvrirait la lecture à tous) : il
 * expose des adresses email de tiers, une donnée sensible.
 */
class NotificationLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeLog(): NotificationLog
    {
        $customer = Customer::create(['name' => 'Client', 'customer_type' => Customer::TYPE_INDIVIDUAL, 'country' => 'France']);

        return NotificationLog::reserve($customer, 'test_event', 'email', 'client@example.test');
    }

    public function test_un_admin_peut_consulter_le_journal(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(NotificationLogResource::canViewAny());
        $this->get(NotificationLogResource::getUrl('index'))->assertSuccessful();
    }

    public function test_un_manager_ne_peut_pas_consulter_le_journal(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertFalse(NotificationLogResource::canViewAny());
        $this->get(NotificationLogResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_viewer_ne_peut_pas_consulter_le_journal(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertFalse(NotificationLogResource::canViewAny());
        $this->get(NotificationLogResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_admin_peut_consulter_le_detail_dune_notification(): void
    {
        $log = $this->makeLog();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(NotificationLogResource::getUrl('view', ['record' => $log]))->assertSuccessful();
    }

    public function test_un_manager_ne_peut_pas_consulter_le_detail_dune_notification(): void
    {
        $log = $this->makeLog();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(NotificationLogResource::getUrl('view', ['record' => $log]))->assertForbidden();
    }

    public function test_la_liste_affiche_bien_les_notifications_existantes(): void
    {
        $log = $this->makeLog();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(\App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs::class)
            ->assertCanSeeTableRecords([$log]);
    }
}
