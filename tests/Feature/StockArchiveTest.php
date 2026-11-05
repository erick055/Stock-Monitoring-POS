<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\InventoryLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;
        return Product::create($attributes + ['sku' => 'ARCHIVE-'.$sequence, 'name' => 'Archive Part '.$sequence, 'is_active' => true]);
    }

    public function test_owner_can_archive_multiple_products_without_changing_stock_or_deleting_history(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $products = collect([$this->product(['current_stock' => 12]), $this->product(['current_stock' => 12])]);
        $untouched = $this->product(['is_active' => true]);
        $history = InventoryLedger::create(['product_id' => $products->first()->product_id, 'user_id' => $owner->id, 'qty_in' => 12, 'qty_out' => 0, 'reason_code' => 'PURCHASE_RECEIPT', 'logs' => 'Original receipt']);
        $promotion = ProductPromotion::create([
            'product_id' => $untouched->product_id, 'bundle_product_id' => $products->first()->product_id,
            'applied_by' => $owner->id, 'action_type' => 'bundle', 'discount_percent' => 10,
            'original_price' => 100, 'promotional_price' => 90, 'started_at' => now(), 'status' => 'active',
        ]);
        $this->actingAs($owner)->from(route('admin.inventory'))->patch(route('admin.inventory.products.archive'), [
            'product_ids' => $products->pluck('product_id')->all(), 'archive_reason' => 'Discontinued parts', 'password' => 'ArchivePassword!123',
        ])->assertRedirect(route('admin.inventory'))->assertSessionHasNoErrors()->assertSessionHas('success');
        foreach ($products as $product) {
            $this->assertDatabaseHas('products', ['product_id' => $product->product_id, 'is_active' => false, 'current_stock' => 12]);
            $this->assertDatabaseHas('inventory_ledgers', ['product_id' => $product->product_id, 'user_id' => $owner->id, 'reason_code' => 'PRODUCT_ARCHIVED', 'qty_in' => 0, 'qty_out' => 0]);
        }
        $this->assertTrue($untouched->fresh()->is_active);
        $this->assertSame('ended', $promotion->fresh()->status);
        $this->assertDatabaseHas('inventory_ledgers', ['ledger_id' => $history->ledger_id, 'qty_in' => 12, 'logs' => 'Original receipt']);
        $response = $this->get(route('admin.inventory'))->assertOk()->assertSee('Archive selected')->assertDontSee('View archived products');
        $response->assertSee('Restore selected')->assertSee('Delete permanently')->assertSee('data-history-group="stock-records"', false);
        $this->assertSame(2, $response->viewData('archivedProducts')->total());
        $this->assertSame(1, $response->viewData('products')->total());
        $response->assertSee('Discontinued parts')->assertSee('data-history-panel="Archived products"', false);
    }

    public function test_staff_cannot_archive_products(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = $this->product(['is_active' => true]);
        $this->actingAs($staff)->patch(route('admin.inventory.products.archive'), ['product_ids' => [$product->product_id]])->assertForbidden();
        $this->patch(route('admin.inventory.archived.manage'), [])->assertForbidden();
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_archive_is_searchable_paginated_and_does_not_include_removed_or_reactivated_products(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 26) as $number) {
            $product = $this->product(['is_active' => false, 'sku' => sprintf('SAVED-%03d', $number),
                'name' => sprintf('Saved part %03d', $number), 'current_stock' => 12]);
            InventoryLedger::create(['product_id' => $product->product_id, 'user_id' => $owner->id,
                'qty_in' => 0, 'qty_out' => 0, 'reason_code' => 'PRODUCT_ARCHIVED', 'logs' => 'Archived for storage']);
        }
        foreach ([false, true] as $active) {
            $product = $this->product(['is_active' => $active]);
            InventoryLedger::create(['product_id' => $product->product_id, 'user_id' => $owner->id,
                'qty_in' => 0, 'qty_out' => 0, 'reason_code' => 'PRODUCT_ARCHIVED']);
            if (! $active) InventoryLedger::create(['product_id' => $product->product_id, 'user_id' => $owner->id,
                'qty_in' => 0, 'qty_out' => 0, 'reason_code' => 'PRODUCT_REMOVED']);
        }
        $response = $this->actingAs($owner)->get(route('admin.inventory'))->assertOk();
        $rows = $response->viewData('archivedProducts');
        $this->assertSame(26, $rows->total());
        $this->assertSame(20, $rows->count());
        $this->assertSame(12, $rows->first()->current_stock);
        $this->get($rows->nextPageUrl())->assertOk()->assertViewHas('archivedProducts', fn ($rows) => $rows->count() === 6);
        $this->get(route('admin.inventory', ['archive_rows' => 50]))->assertOk()->assertViewHas('archivedProducts', fn ($rows) => $rows->count() === 26);
        $this->get(route('admin.inventory', ['archive_search' => 'SAVED-026', 'search' => 'no active match']))->assertOk()
            ->assertViewHas('archivedProducts', fn ($rows) => $rows->total() === 1 && $rows->first()->sku === 'SAVED-026')
            ->assertViewHas('products', fn ($rows) => $rows->total() === 0);
    }

    public function test_wrong_password_and_inactive_selection_leave_all_products_unchanged(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $active = $this->product(['is_active' => true]);
        $inactive = $this->product(['is_active' => false]);
        $this->actingAs($owner)->patch(route('admin.inventory.products.archive'), [
            'product_ids' => [$active->product_id], 'archive_reason' => 'Test', 'password' => 'wrong',
        ])->assertSessionHasErrors('password');
        $this->patch(route('admin.inventory.products.archive'), [
            'product_ids' => [$active->product_id, $inactive->product_id], 'archive_reason' => 'Test', 'password' => 'ArchivePassword!123',
        ])->assertSessionHasErrors('archive');
        $this->assertTrue($active->fresh()->is_active);
        $this->assertDatabaseMissing('inventory_ledgers', ['reason_code' => 'PRODUCT_ARCHIVED']);
    }

    private function archived(Product $product, User $owner): void
    {
        $product->update(['is_active' => false]);
        InventoryLedger::create(['product_id' => $product->product_id, 'user_id' => $owner->id,
            'qty_in' => 0, 'qty_out' => 0, 'reason_code' => 'PRODUCT_ARCHIVED', 'logs' => 'Archive test']);
    }

    public function test_owner_can_restore_selected_archived_products_with_original_quantities(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $product = $this->product(['current_stock' => 12, 'unit_cost' => 100, 'unit_price' => 150]);
        $this->archived($product, $owner);
        $this->actingAs($owner)->patch(route('admin.inventory.archived.manage'), [
            'archived_action' => 'restore', 'archived_product_ids' => [$product->product_id],
            'archived_reason' => 'Selling again', 'password' => 'ArchivePassword!123',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame(12, $product->fresh()->current_stock);
        $this->assertSame('100.00', $product->fresh()->unit_cost);
        $this->assertDatabaseHas('inventory_ledgers', ['product_id' => $product->product_id, 'reason_code' => 'PRODUCT_ARCHIVED']);
        $this->assertDatabaseHas('inventory_ledgers', ['product_id' => $product->product_id, 'reason_code' => 'PRODUCT_RESTORED', 'qty_in' => 0, 'qty_out' => 0]);
        $this->get(route('admin.inventory'))->assertOk()->assertViewHas('archivedProducts', fn ($rows) => $rows->total() === 0)
            ->assertViewHas('products', fn ($rows) => $rows->total() === 1);
    }

    public function test_permanent_delete_removes_archived_product_from_database_after_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $product = $this->product(['current_stock' => 12]);
        $untouched = $this->product();
        $this->archived($product, $owner);
        $payload = ['archived_action' => 'delete', 'archived_product_ids' => [$product->product_id],
            'archived_reason' => 'Retired permanently', 'password' => 'ArchivePassword!123'];
        $this->actingAs($owner)->patch(route('admin.inventory.archived.manage'), $payload)->assertSessionHasErrors('confirm_permanent_delete');
        $this->assertNotNull($product->fresh());
        $this->patch(route('admin.inventory.archived.manage'), $payload + ['confirm_permanent_delete' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('products', ['product_id' => $product->product_id]);
        $this->assertDatabaseMissing('inventory_ledgers', ['product_id' => $product->product_id]);
        $this->assertNotNull($untouched->fresh());
    }

    public function test_archive_actions_require_owner_password_and_entire_selection_to_be_archived(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $product = $this->product();
        $active = $this->product();
        $this->archived($product, $owner);
        $payload = ['archived_action' => 'restore', 'archived_product_ids' => [$product->product_id],
            'archived_reason' => 'Resume sales', 'password' => 'wrong'];
        $this->actingAs($owner)->patch(route('admin.inventory.archived.manage'), $payload)->assertSessionHasErrors('password');
        $this->assertFalse($product->fresh()->is_active);
        $payload['password'] = 'ArchivePassword!123';
        $payload['archived_product_ids'][] = $active->product_id;
        $this->patch(route('admin.inventory.archived.manage'), $payload)->assertSessionHasErrors('archived_products');
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_permanent_delete_keeps_sales_records_and_rolls_back_the_whole_batch(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'password' => Hash::make('ArchivePassword!123')]);
        $free = $this->product();
        $sold = $this->product();
        $sale = \App\Models\SalesTransaction::create(['staff_id' => $owner->id, 'sale_date' => now(), 'payment_status' => 'paid', 'total_sale_amount' => 100]);
        \App\Models\SalesItem::create(['sale_id' => $sale->sale_id, 'product_id' => $sold->product_id, 'quantity' => 1, 'unit_sale_price' => 100, 'line_total' => 100]);
        $this->archived($free, $owner);
        $this->archived($sold, $owner);
        $this->actingAs($owner)->patch(route('admin.inventory.archived.manage'), [
            'archived_action' => 'delete', 'archived_product_ids' => [$free->product_id, $sold->product_id],
            'archived_reason' => 'Retired', 'password' => 'ArchivePassword!123', 'confirm_permanent_delete' => 1,
        ])->assertSessionHasErrors('archived_products');
        $this->assertNotNull($free->fresh());
        $this->assertNotNull($sold->fresh());
        $this->assertDatabaseHas('sales_items', ['sale_id' => $sale->sale_id, 'product_id' => $sold->product_id]);
    }
}

