<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_magarrou_branding(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('Magarrou Group ERP');
        $response->assertSee('images/branding/logoMagarrou.jpeg', false);
        $response->assertSee('images/branding/logoM.png', false);
    }

    public function test_admin_panel_shows_full_and_collapsed_logos(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertSee('Magarrou Group ERP');
        $response->assertSee('images/branding/logoMagarrou.jpeg', false);
        $response->assertSee('images/branding/logoM.png', false);
        $response->assertSee('fi-sidebar-header-collapsed-logo-ctn', false);
    }
}
