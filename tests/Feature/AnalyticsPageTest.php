<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_analytics_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/analytics');

        $response->assertOk();
        $response->assertSee('Sales & Analytics Dashboard');
    }

    public function test_staff_cannot_view_analytics_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/analytics');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_analytics_page(): void
    {
        $response = $this->get('/admin/analytics');

        $response->assertRedirect('/');
    }
}
