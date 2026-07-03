<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_returns_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/returns');

        $response->assertOk();
        $response->assertSee('Return & Damage Management');
    }

    public function test_staff_cannot_view_returns_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/returns');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_returns_page(): void
    {
        $response = $this->get('/admin/returns');

        $response->assertRedirect('/');
    }
}
