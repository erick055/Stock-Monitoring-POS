<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
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
        $response->assertSee('Upload supplier price list');
        $response->assertDontSee('P250');
    }

    public function test_admin_can_preview_and_approve_a_csv_supplier_price_import(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'OIL-001',
            'name' => 'Engine Oil',
            'unit_cost' => 200,
            'unit_price' => 300,
            'is_active' => true,
        ]);
        $csv = implode("\n", [
            'supplier_sku,product_name,internal_sku,currency,unit_price,available_quantity,minimum_order_quantity,lead_time_days,effective_date',
            'SUP-OIL-1,Premium Engine Oil,OIL-001,PHP,245.50,120,6,2,2026-07-24',
        ]);

        $upload = $this->actingAs($admin)->post('/admin/suppliers/imports', [
            'supplier_name' => 'Real Parts Warehouse',
            'supplier_code' => 'RPW',
            'price_file' => UploadedFile::fake()->createWithContent('prices.csv', $csv),
        ]);

        $import = SupplierImport::firstOrFail();
        $upload->assertRedirect(route('admin.suppliers', ['import' => $import->supplier_import_id]));
        $this->assertSame('pending', $import->status);
        $this->assertSame(1, $import->valid_count);
        $this->assertSame(0, $import->error_count);

        $this->actingAs($admin)
            ->get(route('admin.suppliers', ['import' => $import->supplier_import_id]))
            ->assertOk()
            ->assertSee('Premium Engine Oil')
            ->assertSee('OIL-001')
            ->assertSee('Approve and publish prices');

        $this->actingAs($admin)
            ->post(route('admin.suppliers.imports.approve', $import))
            ->assertRedirect(route('admin.suppliers'));

        $price = SupplierPrice::firstOrFail();
        $this->assertSame($product->product_id, $price->product_id);
        $this->assertSame('245.50', $price->unit_price);
        $this->assertSame('approved', $import->fresh()->status);
        $this->assertDatabaseCount('supplier_price_histories', 1);
        $this->assertSame('200.00', $product->fresh()->unit_cost);
    }

    public function test_invalid_spreadsheet_rows_cannot_be_approved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $csv = "supplier_sku,product_name,unit_price\nBAD-1,Bad Price,zero";

        $this->actingAs($admin)->post('/admin/suppliers/imports', [
            'supplier_name' => 'Test Supplier',
            'supplier_code' => 'TEST',
            'price_file' => UploadedFile::fake()->createWithContent('invalid.csv', $csv),
        ])->assertRedirect();

        $import = SupplierImport::firstOrFail();
        $this->assertSame(1, $import->error_count);

        $this->actingAs($admin)
            ->from(route('admin.suppliers', ['import' => $import->supplier_import_id]))
            ->post(route('admin.suppliers.imports.approve', $import))
            ->assertSessionHasErrors('import');

        $this->assertDatabaseCount('supplier_prices', 0);
        $this->assertSame('pending', $import->fresh()->status);
    }

    public function test_admin_can_stage_an_xlsx_supplier_price_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $path = storage_path('framework/testing/supplier-prices.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['supplier_sku', 'product_name', 'unit_price']));
        $writer->addRow(Row::fromValues(['XLSX-001', 'Excel Imported Part', 150.75]));
        $writer->close();

        $file = new UploadedFile(
            $path,
            'supplier-prices.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->actingAs($admin)->post('/admin/suppliers/imports', [
            'supplier_name' => 'Excel Supplier',
            'supplier_code' => 'XLSX',
            'price_file' => $file,
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_import_rows', [
            'supplier_sku' => 'XLSX-001',
            'product_name' => 'Excel Imported Part',
            'unit_price' => 150.75,
        ]);
    }

    public function test_import_smart_matches_by_supplier_sku_and_unique_product_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $skuMatch = Product::create([
            'sku' => 'SUP-PLUG-01', 'name' => 'Different Catalog Name', 'unit_price' => 300,
        ]);
        $nameMatch = Product::create([
            'sku' => 'LOCAL-CHAIN-01', 'name' => 'Premium Drive Chain', 'unit_price' => 900,
        ]);
        $csv = implode("\n", [
            'supplier_sku,product_name,unit_price',
            'sup-plug-01,Supplier Spark Plug,120',
            'CHAIN-SUP-9,  Premium   Drive Chain  ,500',
        ]);

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Smart Match Supplier',
            'supplier_code' => 'SMART-MATCH',
            'price_file' => UploadedFile::fake()->createWithContent('smart.csv', $csv),
        ])->assertRedirect();

        $import = SupplierImport::firstOrFail();
        $this->assertSame($skuMatch->product_id, $import->rows()->where('supplier_sku', 'sup-plug-01')->value('product_id'));
        $this->assertSame($nameMatch->product_id, $import->rows()->where('supplier_sku', 'CHAIN-SUP-9')->value('product_id'));

        $this->actingAs($admin)->post(route('admin.suppliers.imports.approve', $import))->assertRedirect();
        $this->assertSame($skuMatch->product_id, SupplierPrice::where('supplier_sku', 'sup-plug-01')->value('product_id'));
        $this->assertSame($nameMatch->product_id, SupplierPrice::where('supplier_sku', 'CHAIN-SUP-9')->value('product_id'));
    }

    public function test_owner_can_match_a_supplier_price_and_apply_only_its_unit_cost(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'MATCH-01', 'name' => 'Match Target', 'unit_cost' => 100,
            'unit_price' => 250, 'current_stock' => 8,
        ]);
        $price = $this->createSupplierPrice('SUP-MATCH-01', 'Supplier Match Target', 145.50);

        $this->actingAs($admin)->get(route('admin.suppliers'))
            ->assertOk()
            ->assertSee('<strong>Supplier Match Target</strong>', false)
            ->assertSee('Catalog Apply Supplier · Supplier SKU: SUP-MATCH-01')
            ->assertSee('Match or add this item to Products')
            ->assertSee('Create in Products');

        $this->actingAs($admin)
            ->patch(route('admin.suppliers.prices.match', $price), ['product_id' => $product->product_id])
            ->assertSessionHas('success');
        $this->assertSame($product->product_id, $price->fresh()->product_id);

        $this->actingAs($admin)->get(route('admin.suppliers'))
            ->assertOk()
            ->assertSee('Apply supplier cost');

        $this->actingAs($admin)
            ->patch(route('admin.suppliers.prices.apply-cost', $price))
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('145.50', $product->unit_cost);
        $this->assertSame('250.00', $product->unit_price);
        $this->assertSame(8, $product->current_stock);
    }

    public function test_owner_can_create_a_zero_stock_catalog_product_from_an_unmatched_supplier_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $price = $this->createSupplierPrice('NEW-SUP-01', 'Imported Brake Lever', 180.25, 75);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.prices.create-product', $price), [
                'sku' => 'BRK-LEVER-NEW',
                'selling_price' => 295,
                'category' => 'Brake Parts',
                'shelf_location' => 'Aisle C Shelf 2',
                'reorder_level' => 6,
            ])
            ->assertSessionHas('success');

        $product = Product::where('sku', 'BRK-LEVER-NEW')->firstOrFail();
        $this->assertSame('Imported Brake Lever', $product->name);
        $this->assertSame('180.25', $product->unit_cost);
        $this->assertSame('295.00', $product->unit_price);
        $this->assertSame(0, $product->current_stock);
        $this->assertSame('Aisle C Shelf 2', $product->shelf_location);
        $this->assertSame($product->product_id, $price->fresh()->product_id);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'qty_in' => 0,
            'qty_out' => 0,
            'reason_code' => 'SUPPLIER_IMPORT',
        ]);
    }

    public function test_staff_cannot_match_or_apply_supplier_catalog_items(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'NO-STAFF', 'name' => 'Protected Product']);
        $price = $this->createSupplierPrice('NO-STAFF-SUP', 'Protected Supplier Product', 50);

        $this->actingAs($staff)->patch(route('admin.suppliers.prices.match', $price), [
            'product_id' => $product->product_id,
        ])->assertForbidden();
        $this->actingAs($staff)->patch(route('admin.suppliers.prices.apply-cost', $price))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.suppliers.prices.create-product', $price), [
            'sku' => 'FORBIDDEN', 'selling_price' => 100, 'reorder_level' => 5,
        ])->assertForbidden();
    }

    public function test_admin_can_delete_all_supplier_data_and_import_again_without_affecting_inventory(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'PURGE-OIL-01', 'name' => 'Protected Inventory Product',
            'unit_cost' => 200, 'unit_price' => 300, 'current_stock' => 12,
        ]);
        $csv = "supplier_sku,product_name,internal_sku,unit_price\nPURGE-SKU-1,Fresh Oil,PURGE-OIL-01,245.50";

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Resettable Supplier',
            'supplier_code' => 'RESET-ME',
            'price_file' => UploadedFile::fake()->createWithContent('old-prices.csv', $csv),
        ])->assertRedirect();
        $import = SupplierImport::firstOrFail();
        $this->actingAs($admin)->post(route('admin.suppliers.imports.approve', $import))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.suppliers'))
            ->assertSee('Confirm with your account password')
            ->assertDontSee('Type <b>DELETE</b>', false);

        $this->actingAs($admin)
            ->delete(route('admin.suppliers.purge'), ['password' => 'password'])
            ->assertRedirect(route('admin.suppliers'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('supplier_imports', 0);
        $this->assertDatabaseCount('supplier_import_rows', 0);
        $this->assertDatabaseCount('supplier_prices', 0);
        $this->assertDatabaseCount('supplier_price_histories', 0);
        $this->assertSame(12, $product->fresh()->current_stock);
        $this->assertSame('200.00', $product->fresh()->unit_cost);

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'New Supplier Price List',
            'supplier_code' => 'RESET-ME',
            'price_file' => UploadedFile::fake()->createWithContent('new-prices.csv', $csv),
        ])->assertRedirect();

        $this->assertSame(1, Supplier::count());
        $this->assertSame('pending', SupplierImport::firstOrFail()->status);
    }

    public function test_supplier_data_delete_requires_current_admin_password_and_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $supplier = Supplier::create(['name' => 'Protected Supplier', 'code' => 'PROTECTED', 'is_active' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.suppliers.purge'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseHas('suppliers', ['supplier_id' => $supplier->supplier_id]);

        $this->actingAs($staff)
            ->delete(route('admin.suppliers.purge'), ['password' => 'password'])
            ->assertForbidden();
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

    private function createSupplierPrice(string $supplierSku, string $productName, float $unitPrice, ?int $available = null): SupplierPrice
    {
        $supplier = Supplier::create([
            'name' => 'Catalog Apply Supplier',
            'code' => 'CATALOG-'.strtoupper(substr(md5($supplierSku), 0, 8)),
            'is_active' => true,
        ]);

        return SupplierPrice::create([
            'supplier_id' => $supplier->supplier_id,
            'supplier_sku' => $supplierSku,
            'product_name' => $productName,
            'currency' => 'PHP',
            'unit_price' => $unitPrice,
            'available_quantity' => $available,
            'source_type' => 'spreadsheet',
            'source_filename' => 'catalog.csv',
            'last_updated_at' => now(),
        ]);
    }
}
