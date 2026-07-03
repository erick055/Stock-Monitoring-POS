<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuppliersPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_supplier_price_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/suppliers');

        $response->assertOk();
        $response->assertSee('Supplier Price');
    }

    public function test_staff_cannot_view_supplier_price_page(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
        ]);

        $response = $this->actingAs($staff)->get('/admin/suppliers');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_from_supplier_price_page(): void
    {
        $response = $this->get('/admin/suppliers');

        $response->assertRedirect('/');
    }
}
