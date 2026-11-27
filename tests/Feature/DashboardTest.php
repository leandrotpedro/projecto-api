<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_cashier_page_is_available_only_to_cash_management_roles(): void
    {
        $this->withoutVite();
        $client = User::factory()->create();
        $this->actingAs($client)->get('/cashier')->assertForbidden();

        $cashier = User::factory()->create();
        $cashier->forceFill(['role' => 'caixa'])->save();
        $this->actingAs($cashier)->get('/cashier')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('cashier/index')
                ->has('registers')
                ->where('cashSession', null)
                ->where('userRole', 'caixa'));

        $seller = User::factory()->create();
        $seller->forceFill(['role' => 'vendedor'])->save();
        $this->actingAs($seller)->get('/cashier')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('cashier/index')
                ->where('userRole', 'vendedor'));
    }
}
