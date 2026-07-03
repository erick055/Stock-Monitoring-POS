<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadStockPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_dead_stock_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/deadstock');

        $response->assertOk();
        $response->assertSee('Dead Stock Detection');
    }

    public function test_staff_cannot_view_dead_stock_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/deadstock');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_dead_stock_page(): void
    {
        $response = $this->get('/admin/deadstock');

        $response->assertRedirect('/');
    }
}
