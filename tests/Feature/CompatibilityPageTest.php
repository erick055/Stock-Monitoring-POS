<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompatibilityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_compatibility_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/compatibility');

        $response->assertOk();
        $response->assertSee('Part Compatibility');
    }

    public function test_staff_cannot_view_compatibility_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/compatibility');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_compatibility_page(): void
    {
        $response = $this->get('/admin/compatibility');

        $response->assertRedirect('/');
    }
}
