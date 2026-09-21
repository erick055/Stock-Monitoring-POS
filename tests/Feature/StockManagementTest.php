<?php

namespace Tests\Feature;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_stock_management_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->get('/admin/inventory')
            ->assertOk()
            ->assertSee('Stock Management')
            ->assertSee('Current Stock Level')
            ->assertSee('Record Stock Movement')
            ->assertSee('Stock In')
            ->assertSee('Stock Out')
            ->assertSee('Adjustment')
            ->assertSee('Save product changes')
            ->assertSee('Reference number')
            ->assertSee('Resulting stock');

        $this->assertSame(1, substr_count($response->getContent(), 'class="movement-form"'));
        $this->assertSame(1, substr_count($response->getContent(), 'name="movement_type"'));
        $this->assertStringNotContainsString('movement-type-option', $response->getContent());
    }

    public function test_staff_cannot_view_admin_stock_management_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/inventory')->assertForbidden();
        $this->actingAs($staff)->get('/staff/stock-management')->assertNotFound();
        $this->actingAs($staff)->post('/staff/stock-management/products')->assertNotFound();
        $this->actingAs($staff)->post('/staff/stock-management/movements')->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/inventory')->assertRedirect(route('login'));
    }

    public function test_admin_can_sort_current_stock_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'STOCK-LOW', 'name' => 'Small Balance', 'unit_cost' => 50, 'current_stock' => 3]);
        Product::create(['sku' => 'STOCK-HIGH', 'name' => 'Large Balance', 'unit_cost' => 500, 'current_stock' => 40]);

        $this->actingAs($admin)->get('/admin/inventory?sort=stock_high')
            ->assertOk()
            ->assertSee('Sort')
            ->assertSeeInOrder(['Large Balance', 'Small Balance']);

        $this->actingAs($admin)->get('/admin/inventory?sort=cost_low')
            ->assertOk()
            ->assertSeeInOrder(['Small Balance', 'Large Balance']);
    }

    public function test_admin_can_add_a_product_with_an_opening_ledger_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            'sku' => 'ENG-OIL-1L',
            'manufacturer_part_number' => 'MPN-OIL-1L',
            'name' => 'Engine Oil 1L',
            'category' => 'Lubricants',
            'shelf_location' => 'Aisle A · Shelf 03',
            'unit_cost' => 180,
            'unit_price' => 250,
            'reorder_level' => 10,
            'qty_in' => 25,
            'reason_code' => 'OPENING_STOCK',
            'logs' => 'Opening inventory count.',
        ])->assertRedirect()->assertSessionHas('success');

        $product = Product::where('sku', 'ENG-OIL-1L')->firstOrFail();
        $this->assertSame(25, $product->current_stock);
        $this->assertSame('MPN-OIL-1L', $product->manufacturer_part_number);
        $this->assertSame('Aisle A · Shelf 03', $product->shelf_location);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'qty_in' => 25,
            'qty_out' => 0,
            'reason_code' => 'OPENING_STOCK',
        ]);
    }

    public function test_manufacturer_part_number_is_required_when_creating_or_editing_products(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $product = Product::create(['sku' => 'LEGACY', 'name' => 'Legacy product', 'current_stock' => 4]);
        $payload = ['sku' => 'NEW-PART', 'name' => 'New part', 'unit_cost' => 100, 'unit_price' => 150,
            'reorder_level' => 2, 'qty_in' => 5, 'reason_code' => 'OPENING_STOCK'];

        foreach ([[], ['manufacturer_part_number' => null], ['manufacturer_part_number' => '   ']] as $partNumber) {
            $this->post(route('admin.inventory.products.store'), [...$payload, ...$partNumber])
                ->assertSessionHasErrors('manufacturer_part_number');
            $this->patch(route('admin.inventory.products.update', $product), [
                ...$payload, ...$partNumber, 'edit_product_id' => $product->product_id,
            ])->assertSessionHasErrors(['manufacturer_part_number'], null, 'editProduct');
        }

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('inventory_ledgers', 0);
        $this->assertSame('LEGACY', $product->fresh()->sku);
        $this->assertSame(4, $product->fresh()->current_stock);
    }

    public function test_new_product_reuses_existing_category_regardless_of_capitalization(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create([
            'sku' => 'BRK-OLD', 'name' => 'Existing Brake', 'category' => 'Brake Parts',
            'unit_price' => 100, 'current_stock' => 1,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            'sku' => 'BRK-NEW',
            'manufacturer_part_number' => 'MPN-BRK-NEW',
            'name' => 'New Brake',
            'category' => '  brake   parts  ',
            'unit_cost' => 50,
            'unit_price' => 100,
            'reorder_level' => 2,
            'qty_in' => 5,
            'reason_code' => 'OPENING_STOCK',
        ])->assertSessionHas('success');

        $this->assertSame('Brake Parts', Product::where('sku', 'BRK-NEW')->value('category'));
    }

    public function test_previously_deleted_sku_can_be_restored_from_stock_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $deletedProduct = Product::create([
            'sku' => 'RESTORE-SKU-01',
            'name' => 'Old Deleted Product',
            'manufacturer_part_number' => 'OLD-PART-01',
            'unit_cost' => 80,
            'unit_price' => 120,
            'current_stock' => 0,
            'is_active' => false,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            'sku' => 'restore-sku-01',
            'name' => 'Restored Product Model',
            'manufacturer' => 'Restored Brand',
            'manufacturer_part_number' => 'NEW-PART-01',
            'category' => 'Electrical',
            'shelf_location' => 'Rack R-01',
            'unit_cost' => 150,
            'unit_price' => 225,
            'reorder_level' => 4,
            'qty_in' => 12,
            'reason_code' => 'OPENING_STOCK',
        ])->assertRedirect()->assertSessionHas('success');

        $deletedProduct->refresh();
        $this->assertDatabaseCount('products', 1);
        $this->assertTrue($deletedProduct->is_active);
        $this->assertSame('restore-sku-01', $deletedProduct->sku);
        $this->assertSame('Restored Product Model', $deletedProduct->name);
        $this->assertSame(12, $deletedProduct->current_stock);
        $this->assertSame('150.00', $deletedProduct->unit_cost);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $deletedProduct->product_id,
            'qty_in' => 12,
            'reason_code' => 'OPENING_STOCK',
        ]);
    }

    public function test_new_products_cannot_duplicate_sku_name_or_manufacturer_part_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create([
            'sku' => 'BRK-001', 'name' => 'Front Brake Pad', 'manufacturer' => 'Honda',
            'manufacturer_part_number' => 'HND-PAD-01', 'unit_price' => 500,
        ]);
        $base = [
            'unit_cost' => 300, 'unit_price' => 500, 'reorder_level' => 2,
            'qty_in' => 0, 'reason_code' => 'NEW_PRODUCT',
            'manufacturer_part_number' => 'MPN-NEW',
        ];

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            ...$base, 'sku' => ' brk-001 ', 'name' => 'Different Name',
        ])->assertSessionHasErrors('sku');

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            ...$base, 'sku' => 'BRK-002', 'name' => ' front   brake pad ',
        ])->assertSessionHasErrors('name');

        $this->actingAs($admin)->post(route('admin.inventory.products.store'), [
            ...$base, 'sku' => 'BRK-003', 'name' => 'Alternate Pad',
            'manufacturer' => 'honda', 'manufacturer_part_number' => ' hnd-pad-01 ',
        ])->assertSessionHasErrors('manufacturer_part_number');

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_admin_can_assign_and_clear_an_existing_product_shelf_location(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'SHELF-01', 'name' => 'Shelved Product', 'unit_price' => 100, 'current_stock' => 4,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.inventory.products.shelf-location', $product), [
                'shelf_location' => '  Aisle B   Shelf 04  ',
            ])
            ->assertSessionHas('success');

        $this->assertSame('Aisle B Shelf 04', $product->fresh()->shelf_location);

        $this->actingAs($staff)
            ->patch(route('admin.inventory.products.shelf-location', $product), ['shelf_location' => 'Unauthorized'])
            ->assertForbidden();
        $this->assertSame('Aisle B Shelf 04', $product->fresh()->shelf_location);

        $this->actingAs($admin)
            ->patch(route('admin.inventory.products.shelf-location', $product), ['shelf_location' => ''])
            ->assertSessionHas('success');
        $this->assertNull($product->fresh()->shelf_location);
    }

    public function test_admin_can_edit_product_information_and_prices_without_changing_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create([
            'sku' => 'CATEGORY-01', 'name' => 'Category Reference', 'category' => 'Brake Parts',
            'unit_price' => 100, 'current_stock' => 1,
        ]);
        $product = Product::create([
            'sku' => 'EDIT-OLD', 'name' => 'Old Product Name', 'category' => 'Old Category',
            'unit_cost' => 100, 'unit_price' => 150, 'current_stock' => 8, 'reorder_level' => 2,
        ]);

        $this->actingAs($admin)->patch(route('admin.inventory.products.update', $product), [
            'edit_product_id' => $product->product_id,
            'sku' => 'EDIT-NEW',
            'name' => 'Updated Brake Pad',
            'category' => ' brake   parts ',
            'shelf_location' => ' Aisle D   Shelf 2 ',
            'manufacturer' => 'Honda',
            'manufacturer_part_number' => 'HND-100',
            'description' => 'Updated specifications',
            'unit_cost' => 125.50,
            'unit_price' => 225.75,
            'reorder_level' => 4,
        ])->assertRedirect()->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('EDIT-NEW', $product->sku);
        $this->assertSame('Updated Brake Pad', $product->name);
        $this->assertSame('Brake Parts', $product->category);
        $this->assertSame('Aisle D Shelf 2', $product->shelf_location);
        $this->assertSame('125.50', $product->unit_cost);
        $this->assertSame('225.75', $product->unit_price);
        $this->assertSame(4, $product->reorder_level);
        $this->assertSame(8, $product->current_stock);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'qty_in' => 0,
            'qty_out' => 0,
            'reason_code' => 'PRODUCT_UPDATED',
        ]);
        $this->assertStringContainsString('selling price: 150.00 → 225.75', InventoryLedger::latest('ledger_id')->firstOrFail()->logs);
    }

    public function test_product_edit_rejects_a_duplicate_sku_and_staff_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        Product::create(['sku' => 'TAKEN-SKU', 'name' => 'Existing Product', 'unit_price' => 50]);
        $product = Product::create(['sku' => 'EDITABLE-SKU', 'name' => 'Editable Product', 'unit_price' => 100]);
        $payload = [
            'edit_product_id' => $product->product_id,
            'sku' => 'TAKEN-SKU', 'name' => 'Editable Product', 'unit_cost' => 25,
            'manufacturer_part_number' => 'MPN-EDITABLE',
            'unit_price' => 100, 'reorder_level' => 2,
        ];

        $this->actingAs($admin)->patch(route('admin.inventory.products.update', $product), $payload)
            ->assertSessionHasErrors(['sku'], null, 'editProduct');
        $this->assertSame('EDITABLE-SKU', $product->fresh()->sku);

        $this->actingAs($admin)->patch(route('admin.inventory.products.update', $product), [
            ...$payload, 'sku' => 'EDITABLE-SKU', 'name' => ' existing   product ',
        ])->assertSessionHasErrors(['name'], null, 'editProduct');
        $this->assertSame('Editable Product', $product->fresh()->name);

        $this->actingAs($staff)->patch(route('admin.inventory.products.update', $product), [
            ...$payload, 'sku' => 'STAFF-CHANGE',
        ])->assertForbidden();
        $this->assertSame('EDITABLE-SKU', $product->fresh()->sku);
    }

    public function test_admin_can_remove_a_zero_stock_product_while_preserving_its_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'OLD-01', 'name' => 'Discontinued Product', 'unit_price' => 100,
            'current_stock' => 0, 'is_active' => true,
        ]);
        InventoryLedger::create([
            'product_id' => $product->product_id,
            'user_id' => $admin->id,
            'qty_in' => 3,
            'qty_out' => 0,
            'reason_code' => 'OPENING_STOCK',
            'logs' => 'Historical record.',
        ]);
        $promotion = ProductPromotion::create([
            'product_id' => $product->product_id,
            'applied_by' => $admin->id,
            'action_type' => 'discount',
            'discount_percent' => 10,
            'original_price' => 100,
            'promotional_price' => 90,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.inventory.products.destroy', $product), [
                'password' => 'password',
                'deletion_reason' => 'The supplier discontinued this item.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'reason_code' => 'OPENING_STOCK',
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'reason_code' => 'PRODUCT_REMOVED',
            'logs' => 'Removed from active catalog. Reason: The supplier discontinued this item.',
        ]);
        $this->assertSame('ended', $promotion->fresh()->status);
        $this->assertNotNull($promotion->fresh()->ended_at);
    }

    public function test_product_with_remaining_stock_cannot_be_removed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'LIVE-01', 'name' => 'Stocked Product', 'unit_price' => 100,
            'current_stock' => 2, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.inventory.products.destroy', $product), [
                'password' => 'password',
                'deletion_reason' => 'Entered by mistake.',
            ])
            ->assertSessionHasErrors('product');

        $this->assertTrue($product->fresh()->is_active);
        $this->assertDatabaseMissing('inventory_ledgers', [
            'product_id' => $product->product_id,
            'reason_code' => 'PRODUCT_REMOVED',
        ]);
    }

    public function test_product_removal_requires_the_current_admin_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'SECURE-01', 'name' => 'Protected Product', 'unit_price' => 100,
            'current_stock' => 0, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.inventory.products.destroy', $product), [
                'password' => 'incorrect-password',
                'deletion_reason' => 'Duplicate product.',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_staff_cannot_remove_products(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create([
            'sku' => 'ADMIN-ONLY-01', 'name' => 'Admin Only Product', 'unit_price' => 100,
            'current_stock' => 0, 'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->delete(route('admin.inventory.products.destroy', $product), [
                'password' => 'password',
                'deletion_reason' => 'Unauthorized attempt.',
            ])
            ->assertForbidden();

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_stock_in_and_stock_out_update_balance_and_create_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'BRK-PAD-01', 'name' => 'Brake Pad', 'unit_cost' => 500,
            'unit_price' => 750, 'current_stock' => 10, 'reorder_level' => 3,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'in',
            'quantity' => 5,
            'reason_code' => 'PURCHASE_RECEIPT',
            'logs' => 'Supplier delivery.',
        ])->assertSessionHas('success');

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 4,
            'reason_code' => 'SALE',
            'logs' => 'POS sale.',
        ])->assertSessionHas('success');

        $this->assertSame(11, $product->fresh()->current_stock);
        $this->assertSame(2, InventoryLedger::where('product_id', $product->product_id)->count());
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 5, 'qty_out' => 0]);
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 0, 'qty_out' => 4]);
    }

    public function test_stock_cannot_be_adjusted_below_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'FILTER-01', 'name' => 'Oil Filter', 'unit_cost' => 100,
            'unit_price' => 180, 'current_stock' => 2, 'reorder_level' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 3,
            'reason_code' => 'SALE',
        ])->assertRedirect()->assertSessionHasErrors('quantity');

        $this->assertSame(2, $product->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_combined_movement_form_documents_reference_source_and_notes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'CHAIN-01', 'name' => 'Drive Chain', 'unit_price' => 900,
            'current_stock' => 5, 'reorder_level' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'in',
            'quantity' => 10,
            'reason_code' => 'PURCHASE_RECEIPT',
            'reference' => 'DR-2026-001',
            'counterparty' => 'Moto Parts Supplier',
            'logs' => 'Boxes inspected and complete.',
        ])->assertSessionHas('success', 'Stock in recorded successfully. New balance: 15 unit(s).');

        $ledger = InventoryLedger::firstOrFail();
        $this->assertSame(10, $ledger->qty_in);
        $this->assertStringContainsString('Reference: DR-2026-001', $ledger->logs);
        $this->assertStringContainsString('Source / destination: Moto Parts Supplier', $ledger->logs);
        $this->assertStringContainsString('Notes: Boxes inspected and complete.', $ledger->logs);
    }

    public function test_movement_reason_must_match_selected_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'MISMATCH-01', 'name' => 'Mismatch Product', 'unit_price' => 100,
            'current_stock' => 5,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'out',
            'quantity' => 1,
            'reason_code' => 'PURCHASE_RECEIPT',
        ])->assertSessionHasErrors('reason_code');

        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_adjustment_accepts_signed_count_differences(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'COUNT-01', 'name' => 'Counted Product', 'unit_price' => 100,
            'current_stock' => 10,
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'adjustment',
            'quantity' => -2,
            'reason_code' => 'PHYSICAL_COUNT',
        ])->assertSessionHas('success', 'Stock adjustment recorded successfully. New balance: 8 unit(s).');

        $this->actingAs($admin)->post(route('admin.inventory.movements.store'), [
            'product_id' => $product->product_id,
            'movement_type' => 'adjustment',
            'quantity' => 3,
            'reason_code' => 'DATA_CORRECTION',
        ])->assertSessionHas('success', 'Stock adjustment recorded successfully. New balance: 11 unit(s).');

        $this->assertSame(11, $product->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 0, 'qty_out' => 2]);
        $this->assertDatabaseHas('inventory_ledgers', ['qty_in' => 3, 'qty_out' => 0]);
    }
}
