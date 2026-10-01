<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadStockPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_sale_age_counts_elapsed_days_and_handles_missing_or_future_dates(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 12:00:00'));
        $product = Product::create([
            'sku' => 'SALE-AGE', 'name' => 'Sale Age Part',
            'unit_cost' => 100, 'unit_price' => 150, 'current_stock' => 5,
        ]);
        $controller = app(\App\Http\Controllers\DeadStockController::class);

        foreach ([0, 1, 30, 90, 120] as $days) {
            $analysis = $controller->scoreProduct($product, 0, 1, now()->subDays($days)->toDateTimeString());
            $this->assertSame("{$days} day(s) ago", $analysis['last_sale']);
        }
        $this->assertSame('0 day(s) ago', $controller->scoreProduct(
            $product, 0, 1, now()->subHours(23)->toDateTimeString()
        )['last_sale']);
        $this->assertSame('0 day(s) ago', $controller->scoreProduct(
            $product, 0, 1, now()->addDays(2)->toDateTimeString()
        )['last_sale']);
        $this->assertSame('No sale recorded', $controller->scoreProduct($product, 0, 0, null)['last_sale']);
    }

    public function test_admin_can_view_dead_stock_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get('/admin/deadstock');

        $response->assertOk();
        $response->assertSee('Dead Stock Detection');
    }

    public function test_dead_stock_page_shows_live_dead_and_slow_moving_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $deadProduct = Product::create([
            'sku' => 'OLD-BLOCK',
            'name' => 'Old Engine Block',
            'category' => 'Engine',
            'unit_cost' => 1000,
            'unit_price' => 1500,
            'current_stock' => 4,
        ]);
        $slowProduct = Product::create([
            'sku' => 'SLOW-EXH',
            'name' => 'Premium Exhaust',
            'category' => 'Exhaust',
            'unit_cost' => 3000,
            'unit_price' => 4200,
            'current_stock' => 8,
        ]);
        $healthyProduct = Product::create([
            'sku' => 'FAST-OIL',
            'name' => 'Fast Oil',
            'category' => 'Oils',
            'unit_cost' => 120,
            'unit_price' => 250,
            'current_stock' => 20,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => 500,
            'tax_amount' => 60,
            'total_sale_amount' => 560,
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $slowProduct->product_id,
            'quantity' => 1,
            'unit_sale_price' => 4200,
            'unit_cost' => 3000,
            'line_total' => 4200,
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $healthyProduct->product_id,
            'quantity' => 8,
            'unit_sale_price' => 250,
            'unit_cost' => 120,
            'line_total' => 2000,
        ]);

        $response = $this->actingAs($admin)->get('/admin/deadstock');

        $response->assertOk()
            ->assertSee('Old Engine Block')
            ->assertSee('Premium Exhaust')
            ->assertSee('₱4,000.00')
            ->assertSee('1 units / month')
            ->assertSee('Measured inventory risk score')
            ->assertSee('Dead Stock')
            ->assertSee('No POS sales recorded in the last 90 days')
            ->assertSee('Apply a targeted discount')
            ->assertDontSee('Clearance sale');

        $response->assertViewHas('riskItems', fn ($items) => $items
            ->contains(fn ($item) => $item['product_id'] === $deadProduct->product_id)
            && $items->contains(fn ($item) => $item['product_id'] === $slowProduct->product_id)
            && ! $items->contains(fn ($item) => $item['product_id'] === $healthyProduct->product_id));
    }

    public function test_at_risk_inventory_is_searchable_filtered_and_paginated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 35) as $index) {
            Product::create([
                'sku' => sprintf('DEAD-%03d', $index),
                'name' => "Dead Product {$index}",
                'category' => 'Bulk',
                'unit_cost' => 100,
                'unit_price' => 150,
                'current_stock' => 2,
                'reorder_level' => 5,
            ]);
        }

        $this->actingAs($admin)->get(route('admin.dead-stock'))
            ->assertOk()
            ->assertViewHas('riskItems', fn ($items) => $items->total() === 35 && $items->count() === 25 && $items->lastPage() === 2);

        $this->actingAs($admin)->get(route('admin.dead-stock', ['search' => 'DEAD-035']))
            ->assertOk()
            ->assertSee('Dead Product 35')
            ->assertViewHas('riskItems', fn ($items) => $items->total() === 1
                && $items->first()['product_id'] === Product::where('sku', 'DEAD-035')->value('product_id'));
    }

    public function test_admin_can_approve_replace_and_end_a_dead_stock_offer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'PROMO-DEAD-01',
            'name' => 'Unlikely Seller',
            'unit_cost' => 500,
            'unit_price' => 1000,
            'current_stock' => 8,
            'reorder_level' => 2,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'discount',
                'discount_percent' => 15,
            ])
            ->assertRedirect();

        $promotion = ProductPromotion::firstOrFail();
        $this->assertSame('discount', $promotion->action_type);
        $this->assertSame('15.00', $promotion->discount_percent);
        $this->assertSame('850.00', $promotion->promotional_price);
        $this->assertSame($admin->id, $promotion->applied_by);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'discount',
                'discount_percent' => 30,
            ])
            ->assertRedirect();

        $this->assertSame('ended', $promotion->fresh()->status);
        $this->assertDatabaseHas('product_promotions', [
            'product_id' => $product->product_id,
            'action_type' => 'discount',
            'discount_percent' => 30,
            'promotional_price' => 700,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.dead-stock.promotions.end', $product))
            ->assertRedirect();

        $this->assertDatabaseMissing('product_promotions', [
            'product_id' => $product->product_id,
            'status' => 'active',
        ]);
    }

    public function test_clearance_sale_cannot_be_created_from_dead_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'NO-CLEARANCE-01', 'name' => 'No Clearance Product',
            'unit_price' => 500, 'current_stock' => 5,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'clearance',
                'discount_percent' => 30,
            ])
            ->assertSessionHasErrors('action_type');

        $this->assertDatabaseCount('product_promotions', 0);
    }

    public function test_promo_bundle_requires_a_searchable_inventory_product_and_sets_zero_pos_value(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'BUNDLE-DEAD-01', 'name' => 'Bundle Candidate',
            'unit_price' => 500, 'current_stock' => 5,
        ]);
        $companion = Product::create([
            'sku' => 'BUNDLE-OIL-01', 'name' => 'Bundle Engine Oil',
            'unit_price' => 250, 'current_stock' => 10,
        ]);

        $this->actingAs($admin)->get(route('admin.dead-stock'))
            ->assertOk()
            ->assertSee('Bundle Engine Oil')
            ->assertSee('bundle-product-options');

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'promo_bundle',
            ])
            ->assertSessionHasErrors('bundle_product_id');

        $this->assertDatabaseCount('product_promotions', 0);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'promo_bundle',
                'bundle_product_id' => $companion->product_id,
            ])
            ->assertRedirect();

        $promotion = ProductPromotion::firstOrFail();
        $this->assertSame($companion->product_id, $promotion->bundle_product_id);
        $this->assertSame('0.00', $promotion->promotional_price);
        $this->assertSame('100.00', $promotion->discount_percent);
    }

    public function test_admin_cannot_promote_a_product_the_latest_scan_marks_healthy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'HEALTHY-PROMO-01', 'name' => 'Healthy Seller',
            'unit_cost' => 50, 'unit_price' => 100, 'current_stock' => 5, 'reorder_level' => 5,
        ]);
        $sale = SalesTransaction::create([
            'staff_id' => $staff->id,
            'subtotal' => 1000,
            'tax_amount' => 120,
            'total_sale_amount' => 1120,
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);
        SalesItem::create([
            'sale_id' => $sale->sale_id,
            'product_id' => $product->product_id,
            'quantity' => 10,
            'unit_sale_price' => 100,
            'unit_cost' => 50,
            'line_total' => 1000,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'discount', 'discount_percent' => 10,
            ])
            ->assertSessionHasErrors('promotion');

        $this->assertDatabaseCount('product_promotions', 0);
    }

    public function test_admin_can_archive_and_restore_dead_stock_without_changing_inventory_or_pos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'ARCHIVE-DEAD-01', 'name' => 'Archived Dead Part',
            'unit_cost' => 200, 'unit_price' => 350, 'current_stock' => 5, 'reorder_level' => 5,
        ]);

        $this->actingAs($admin)->patch(route('admin.dead-stock.archive', $product), [
            'archive_note' => 'Recovery action documented; hide from the working queue.',
        ])->assertRedirect()->assertSessionHas('success');

        $product->refresh();
        $this->assertNotNull($product->dead_stock_archived_at);
        $this->assertSame($admin->id, $product->dead_stock_archived_by);
        $this->assertSame(5, $product->current_stock);
        $this->assertTrue($product->is_active);

        $this->actingAs($admin)->get(route('admin.dead-stock'))
            ->assertOk()
            ->assertViewHas('riskItems', fn ($items) => ! $items->contains(fn ($item) => $item['product_id'] === $product->product_id));

        $this->actingAs($admin)->get(route('admin.dead-stock', ['status' => 'archived']))
            ->assertOk()
            ->assertSee('Archived Dead-Stock Items')
            ->assertSee('Recovery action documented')
            ->assertSee('Restore to active queue')
            ->assertViewHas('riskItems', fn ($items) => $items->contains(fn ($item) => $item['product_id'] === $product->product_id));

        $this->actingAs($staff)->get(route('staff.pos'))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->contains(fn ($item) => $item['id'] === $product->product_id));

        $this->actingAs($admin)->patch(route('admin.dead-stock.restore', $product))
            ->assertRedirect(route('admin.dead-stock'));

        $this->assertNull($product->fresh()->dead_stock_archived_at);
        $this->actingAs($admin)->get(route('admin.dead-stock'))
            ->assertViewHas('riskItems', fn ($items) => $items->contains(fn ($item) => $item['product_id'] === $product->product_id));
    }

    public function test_staff_cannot_archive_or_restore_dead_stock(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'ARCHIVE-PROTECTED-01', 'name' => 'Archive Protected Part',
            'unit_price' => 350, 'current_stock' => 5,
        ]);

        $this->actingAs($staff)->patch(route('admin.dead-stock.archive', $product))->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.dead-stock.restore', $product))->assertForbidden();
        $this->assertNull($product->fresh()->dead_stock_archived_at);
    }

    public function test_staff_cannot_manage_dead_stock_offers(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'STAFF-PROMO-01', 'name' => 'Protected Promotion',
            'unit_price' => 500, 'current_stock' => 5,
        ]);

        $this->actingAs($staff)
            ->post(route('admin.dead-stock.promotions.apply', $product), [
                'action_type' => 'discount', 'discount_percent' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('product_promotions', 0);
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
