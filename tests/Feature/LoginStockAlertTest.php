<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginStockAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_all_critical_active_products_once_after_login_even_on_an_intended_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 30) as $number) {
            Product::create(['sku' => 'CRITICAL-'.$number, 'name' => 'Critical product '.$number,
                'current_stock' => $number === 1 ? 0 : 5, 'reorder_level' => 5, 'is_active' => true]);
        }
        Product::create(['sku' => 'HEALTHY', 'name' => 'Healthy product', 'current_stock' => 6, 'reorder_level' => 5]);
        Product::create(['sku' => 'INACTIVE', 'name' => 'Inactive product', 'current_stock' => 0, 'reorder_level' => 5, 'is_active' => false]);

        $response = $this->actingAs($admin)->withSession(['stock.login_alert_pending' => true])
            ->get(route('admin.accounts'))->assertOk()->assertSee('data-login-stock-alert', false)
            ->assertSee('30 active products need')->assertSee('Out of stock')
            ->assertDontSee('Healthy product')->assertDontSee('Inactive product');
        foreach (range(1, 30) as $number) {
            $response->assertSee('CRITICAL-'.$number);
        }
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-login-stock-alert', false);
    }

    public function test_staff_does_not_receive_owner_stock_popup(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Product::create(['sku' => 'LOW', 'name' => 'Low product', 'current_stock' => 0, 'reorder_level' => 5]);
        $this->actingAs($staff)->withSession(['stock.login_alert_pending' => true])
            ->get(route('staff.dashboard'))->assertOk()->assertDontSee('data-login-stock-alert', false);
    }

    public function test_no_popup_when_stock_is_healthy_or_no_login_is_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'HEALTHY', 'name' => 'Healthy product', 'current_stock' => 6, 'reorder_level' => 5]);
        $this->actingAs($admin)->withSession(['stock.login_alert_pending' => true])
            ->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-login-stock-alert', false);
        $product->update(['current_stock' => 0]);
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-login-stock-alert', false);
    }
}
