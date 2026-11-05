<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SystemRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_and_session_polling_do_not_consume_action_limits(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'RATE-PART', 'name' => 'Rate Part', 'current_stock' => 0]);
        $this->actingAs($owner);
        for ($i = 0; $i < 35; $i++) {
            $this->getJson(route('inventory.live'))->assertOk();
            $this->getJson(route('session.status'))->assertOk();
        }
        $this->postJson(route('session.activity'))->assertNoContent();
        $this->get(route('admin.analytics.export'))->assertOk();
        $this->delete(route('admin.inventory.products.destroy', $product))->assertSessionHasErrors('password');
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_action_cooldown_redirects_to_its_page_and_does_not_block_other_operations(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'COOLDOWN-PART', 'name' => 'Cooldown Part', 'current_stock' => 0]);
        $this->actingAs($owner);
        for ($i = 0; $i < 5; $i++) {
            $this->delete(route('admin.inventory.products.destroy', $product))->assertSessionHasErrors('password');
        }
        $this->delete(route('admin.inventory.products.destroy', $product))
            ->assertRedirect(route('admin.inventory'))->assertSessionHasErrors('rate_limit')->assertHeader('Retry-After');
        $this->get(route('admin.inventory'))->assertOk()->assertSee('This request was not processed.');
        $this->getJson(route('inventory.live'))->assertOk();
        $this->deleteJson(route('admin.suppliers.purge'))->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->delete(route('admin.inventory.products.destroy', $product))->assertSessionHasErrors('password');
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_guest_login_resend_cooldown_does_not_block_password_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'staff']);
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login.verify.resend'))->assertRedirect(route('login'));
        }
        $this->post(route('login.verify.resend'))->assertRedirect(route('login.verify'))->assertSessionHasErrors('rate_limit');
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    }

    public function test_password_reset_cooldown_without_a_token_has_a_valid_destination(): void
    {
        for ($i = 0; $i < 5; $i++) $this->post(route('password.update'))->assertSessionHasErrors('token');
        $this->post(route('password.update'))->assertRedirect(route('password.request'))->assertSessionHasErrors('rate_limit');
    }

    public function test_routes_use_named_isolated_rate_limits(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                $this->assertDoesNotMatchRegularExpression('/^throttle:\d/', $middleware, $route->uri());
            }
        }
    }
}
