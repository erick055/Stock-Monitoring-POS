<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStocksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_low_stocks_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/low-stocks');

        $response->assertOk();
        $response->assertSee('Stock Alerts and Monitoring');
    }

    public function test_staff_cannot_view_low_stocks_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/low-stocks');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_low_stocks_page(): void
    {
        $response = $this->get('/admin/low-stocks');

        $response->assertRedirect('/');
    }
}
