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

    public function test_import_archive_lists_older_uploads_and_paginates_the_original_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Archive Supplier', 'code' => 'ARCHIVE']);
        $oldImport = SupplierImport::create([
            'supplier_id' => $supplier->supplier_id, 'source_filename' => 'original.csv',
            'status' => 'approved', 'uploaded_by' => $admin->id, 'row_count' => 26,
            'valid_count' => 26, 'created_at' => now()->subMonth(),
        ]);
        for ($i = 1; $i <= 26; $i++) {
            $oldImport->rows()->create([
                'row_number' => $i, 'supplier_sku' => 'OLD-'.$i,
                'product_name' => 'Original item '.$i, 'unit_price' => 123.45,
            ]);
        }
        for ($i = 0; $i < 11; $i++) {
            SupplierImport::create([
                'supplier_id' => $supplier->supplier_id, 'source_filename' => 'new-'.$i.'.csv',
                'status' => 'approved', 'uploaded_by' => $admin->id,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('admin.suppliers', ['import' => $oldImport->supplier_import_id]));
        $response->assertOk()->assertSee('Imported price archive')->assertSee('original.csv')
            ->assertSee('PHP 123.45')->assertSee('Original item 1')
            ->assertDontSee('Original item 26')->assertDontSee('Approve and publish prices')
            ->assertDontSee('Reject import');
        $this->assertCount(12, $response->viewData('imports'));
        $this->assertSame($oldImport->supplier_import_id, $response->viewData('imports')->last()->supplier_import_id);
        $this->assertSame(26, $response->viewData('importRows')->total());

        $this->get($response->viewData('importRows')->nextPageUrl())->assertOk()
            ->assertSee('Original item 26')->assertSee('PHP 123.45');
        $this->assertSame('123.45', $oldImport->rows()->first()->unit_price);
    }

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

    public function test_existing_supplier_price_is_automatically_matched_when_sku_is_the_same(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'sku' => 'AUTO-SKU-100',
            'name' => 'Automatic Match Model',
            'unit_cost' => 175,
            'unit_price' => 260,
        ]);
        $supplierPrice = $this->createSupplierPrice(' auto-sku-100 ', 'Supplier Description', 165);
        $this->assertNull($supplierPrice->product_id);

        $this->actingAs($admin)->get(route('admin.suppliers'))
            ->assertOk()
            ->assertSee('Automatically matched by SKU: AUTO-SKU-100')
            ->assertSee('No manual matching needed')
            ->assertSee('Automatic Match Model')
            ->assertSee('PHP 165.00')
            ->assertDontSee('Match an existing product');

        $this->assertSame($product->product_id, $supplierPrice->fresh()->product_id);

        $this->actingAs($admin)
            ->delete(route('admin.suppliers.prices.unmatch', $supplierPrice))
            ->assertRedirect()
            ->assertSessionHas('success');

        $supplierPrice->refresh();
        $product->refresh();
        $this->assertNull($supplierPrice->product_id);
        $this->assertTrue($supplierPrice->auto_match_disabled);
        $this->assertSame('175.00', $product->unit_cost);
        $this->assertSame('260.00', $product->unit_price);

        $this->actingAs($admin)->get(route('admin.suppliers'))
            ->assertOk()
            ->assertSee('! NOT MATCHED')
            ->assertSee('Match an existing product')
            ->assertDontSee('Automatically matched by SKU: AUTO-SKU-100');

        $this->assertNull($supplierPrice->fresh()->product_id);
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
            ->assertSee('Review import decision')
            ->assertSee('Accept and publish prices')
            ->assertSee('Reject import');

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

    public function test_the_same_supplier_file_cannot_be_imported_twice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $csv = "supplier_sku,product_name,unit_price\nDUP-1,Duplicate Guard Part,150.00";

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Duplicate Guard Supplier',
            'supplier_code' => 'DUP-GUARD',
            'price_file' => UploadedFile::fake()->createWithContent('prices.xlsx.csv', $csv),
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Duplicate Guard Supplier',
            'supplier_code' => 'DUP-GUARD',
            'price_file' => UploadedFile::fake()->createWithContent('renamed-prices.csv', $csv),
        ])->assertSessionHasErrors('price_file');

        $this->assertDatabaseCount('supplier_imports', 1);
        $this->assertDatabaseCount('supplier_import_rows', 1);
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
        $partNumberMatch = Product::create([
            'sku' => 'LOCAL-BRAKE-01', 'name' => 'Rear Brake Shoe',
            'manufacturer_part_number' => 'MPN-BRK-778', 'unit_price' => 650,
        ]);
        $csv = implode("\n", [
            'supplier_sku,product_name,unit_price',
            'sup-plug-01,Supplier Spark Plug,120',
            'CHAIN-SUP-9,  Premium   Drive Chain  ,500',
            'mpn-brk-778,Supplier Rear Brake,310',
        ]);

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'Smart Match Supplier',
            'supplier_code' => 'SMART-MATCH',
            'price_file' => UploadedFile::fake()->createWithContent('smart.csv', $csv),
        ])->assertRedirect();

        $import = SupplierImport::firstOrFail();
        $this->assertSame($skuMatch->product_id, $import->rows()->where('supplier_sku', 'sup-plug-01')->value('product_id'));
        $this->assertSame($nameMatch->product_id, $import->rows()->where('supplier_sku', 'CHAIN-SUP-9')->value('product_id'));
        $this->assertSame($partNumberMatch->product_id, $import->rows()->where('supplier_sku', 'mpn-brk-778')->value('product_id'));

        $this->actingAs($admin)->post(route('admin.suppliers.imports.approve', $import))->assertRedirect();
        $this->assertSame($skuMatch->product_id, SupplierPrice::where('supplier_sku', 'sup-plug-01')->value('product_id'));
        $this->assertSame($nameMatch->product_id, SupplierPrice::where('supplier_sku', 'CHAIN-SUP-9')->value('product_id'));
        $this->assertSame($partNumberMatch->product_id, SupplierPrice::where('supplier_sku', 'mpn-brk-778')->value('product_id'));
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
            ->assertSee('Apply supplier cost')
            ->assertSee('PRODUCT MATCHED')
            ->assertSee('Product SKU')
            ->assertSee('MATCH-01')
            ->assertSee('Model / Product Name')
            ->assertSee('Match Target')
            ->assertSee('Supplier Unit Price')
            ->assertSee('PHP 145.50')
            ->assertSee('Product Unit Cost')
            ->assertSee('₱100.00')
            ->assertSee('Product Selling Price')
            ->assertSee('₱250.00')
            ->assertSee('₱45.50 · Supplier is higher')
            ->assertSee('Profit if applied: ₱104.50 per unit');

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
                'manufacturer_part_number' => 'MPN-LEVER-NEW',
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
        $this->assertSame('MPN-LEVER-NEW', $product->manufacturer_part_number);
        $this->assertSame($product->product_id, $price->fresh()->product_id);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->product_id,
            'qty_in' => 0,
            'qty_out' => 0,
            'reason_code' => 'SUPPLIER_IMPORT',
        ]);
    }

    public function test_owner_can_bulk_create_selected_supplier_items_as_zero_stock_products(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->createSupplierPrice('BULK-ONE', 'Bulk Product One', 100, 25);
        $second = $this->createSupplierPrice('BULK-TWO', 'Bulk Product Two', 200, 30);

        $this->actingAs($admin)->get(route('admin.suppliers'))
            ->assertOk()
            ->assertSee('Bulk add selected')
            ->assertSee('Select all eligible')
            ->assertSee('data-bulk-select-all', false)
            ->assertSee('data-bulk-product-checkbox', false);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.prices.bulk-create-products'), [
                'supplier_price_ids' => [$first->supplier_price_id, $second->supplier_price_id],
                'markup_percent' => 25,
                'category' => 'Bulk Parts',
                'shelf_location' => 'Bulk Rack',
                'reorder_level' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $one = Product::where('sku', 'BULK-ONE')->firstOrFail();
        $two = Product::where('sku', 'BULK-TWO')->firstOrFail();
        $this->assertSame('125.00', $one->unit_price);
        $this->assertSame('250.00', $two->unit_price);
        $this->assertSame(0, $one->current_stock);
        $this->assertSame('BULK-ONE', $one->manufacturer_part_number);
        $this->assertSame($one->product_id, $first->fresh()->product_id);
        $this->assertSame($two->product_id, $second->fresh()->product_id);
        $this->assertDatabaseCount('inventory_ledgers', 2);
    }

    public function test_owner_can_restore_a_deleted_sku_from_supplier_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $deletedProduct = Product::create([
            'sku' => 'RESTORE-SUP-01',
            'name' => 'Deleted Supplier Product',
            'manufacturer_part_number' => 'OLD-SUP-PART',
            'unit_cost' => 100,
            'unit_price' => 160,
            'current_stock' => 0,
            'is_active' => false,
        ]);
        $supplierPrice = $this->createSupplierPrice('SUP-RESTORE-01', 'Restored Supplier Model', 145.50, 30);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.prices.create-product', $supplierPrice), [
                'sku' => 'restore-sup-01',
                'manufacturer_part_number' => 'NEW-SUP-PART',
                'selling_price' => 240,
                'category' => 'Engine Parts',
                'shelf_location' => 'Rack S-02',
                'reorder_level' => 6,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $deletedProduct->refresh();
        $this->assertDatabaseCount('products', 1);
        $this->assertTrue($deletedProduct->is_active);
        $this->assertSame('Restored Supplier Model', $deletedProduct->name);
        $this->assertSame('145.50', $deletedProduct->unit_cost);
        $this->assertSame('240.00', $deletedProduct->unit_price);
        $this->assertSame(0, $deletedProduct->current_stock);
        $this->assertSame($deletedProduct->product_id, $supplierPrice->fresh()->product_id);
    }

    public function test_supplier_product_creation_requires_manufacturer_part_number(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $price = $this->createSupplierPrice('REQUIRED-MPN', 'Supplier part', 100);

        foreach ([[], ['manufacturer_part_number' => null], ['manufacturer_part_number' => '   ']] as $partNumber) {
            $this->post(route('admin.suppliers.prices.create-product', $price), [
                'sku' => 'NEW-MPN', 'selling_price' => 150, 'reorder_level' => 2, ...$partNumber,
            ])->assertSessionHasErrors('manufacturer_part_number');
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_ledgers', 0);
        $this->assertNull($price->fresh()->product_id);
    }

    public function test_supplier_item_cannot_create_a_duplicate_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::create([
            'sku' => 'CATALOG-EXISTING', 'name' => 'Existing Supplier Part',
            'unit_price' => 200, 'current_stock' => 2,
        ]);
        $supplierPrice = $this->createSupplierPrice('SUP-DUPLICATE', ' existing   supplier part ', 125);

        $this->actingAs($admin)->post(route('admin.suppliers.prices.create-product', $supplierPrice), [
            'sku' => 'CATALOG-NEW', 'selling_price' => 250, 'reorder_level' => 3,
            'manufacturer_part_number' => 'MPN-CATALOG-NEW',
        ])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('products', 1);
        $this->assertNull($supplierPrice->fresh()->product_id);
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
        $this->actingAs($staff)->delete(route('admin.suppliers.prices.unmatch', $price))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.suppliers.prices.create-product', $price), [
            'sku' => 'FORBIDDEN', 'selling_price' => 100, 'reorder_level' => 5,
        ])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.suppliers.prices.bulk-create-products'), [
            'supplier_price_ids' => [$price->supplier_price_id], 'markup_percent' => 30, 'reorder_level' => 5,
        ])->assertForbidden();
    }

    public function test_admin_can_clear_prices_archive_the_import_and_import_a_new_file_without_affecting_inventory(): void
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

        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('supplier_imports', 1);
        $this->assertDatabaseCount('supplier_import_rows', 1);
        $this->assertDatabaseCount('supplier_prices', 0);
        $this->assertDatabaseCount('supplier_price_histories', 0);
        $this->assertNotNull($import->fresh()->archived_at);
        $this->assertFalse($import->supplier->fresh()->is_active);
        $this->assertSame(12, $product->fresh()->current_stock);
        $this->assertSame('200.00', $product->fresh()->unit_cost);

        $this->actingAs($admin)
            ->get(route('admin.suppliers', ['import' => $import->supplier_import_id]))
            ->assertOk()
            ->assertSee('ARCHIVED IMPORT · READ ONLY')
            ->assertSee('Fresh Oil')
            ->assertSee('PHP 245.50');

        $this->actingAs($admin)->post(route('admin.suppliers.imports.upload'), [
            'supplier_name' => 'New Supplier Price List',
            'supplier_code' => 'RESET-ME',
            'price_file' => UploadedFile::fake()->createWithContent(
                'new-prices.csv',
                "supplier_sku,product_name,internal_sku,unit_price\nPURGE-SKU-1,Fresh Oil,PURGE-OIL-01,250.00"
            ),
        ])->assertRedirect();

        $this->assertSame(1, Supplier::count());
        $this->assertSame(2, SupplierImport::count());
        $this->assertSame('pending', SupplierImport::latest('supplier_import_id')->firstOrFail()->status);
        $this->assertTrue(Supplier::firstOrFail()->is_active);
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

    public function test_owner_can_sort_published_supplier_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createSupplierPrice('SORT-A', 'Affordable Part', 120, 5);
        $this->createSupplierPrice('SORT-Z', 'Premium Part', 950, 50);

        $this->actingAs($admin)->get(route('admin.suppliers', ['sort' => 'price_high']))
            ->assertOk()
            ->assertSee('Sort prices')
            ->assertSeeInOrder(['Premium Part', 'Affordable Part']);

        $this->actingAs($admin)->get(route('admin.suppliers', ['sort' => 'product']))
            ->assertOk()
            ->assertSeeInOrder(['Affordable Part', 'Premium Part']);
    }

    public function test_owner_can_search_supplier_prices_by_product_and_supplier_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Metro Parts Supply', 'code' => 'METRO', 'is_active' => true]);
        $product = Product::create([
            'sku' => 'CHAIN-520', 'name' => 'Heavy Duty Chain', 'category' => 'Drive Components',
            'manufacturer_part_number' => 'DID-520', 'unit_price' => 900,
        ]);
        SupplierPrice::create([
            'supplier_id' => $supplier->supplier_id, 'product_id' => $product->product_id,
            'supplier_sku' => 'SUP-CHAIN', 'product_name' => 'Chain Set', 'currency' => 'PHP',
            'unit_price' => 600, 'source_type' => 'spreadsheet', 'source_filename' => 'search.csv',
            'last_updated_at' => now(),
        ]);
        SupplierPrice::create([
            'supplier_id' => $supplier->supplier_id, 'supplier_sku' => 'SUP-OIL',
            'product_name' => 'Engine Oil', 'currency' => 'PHP', 'unit_price' => 200,
            'source_type' => 'spreadsheet', 'source_filename' => 'search.csv', 'last_updated_at' => now(),
        ]);

        foreach (['Heavy Duty Chain', 'CHAIN-520', 'Drive Components', 'DID-520'] as $search) {
            $this->actingAs($admin)->get(route('admin.suppliers', ['search' => $search]))
                ->assertOk()->assertSee('Chain Set')->assertDontSee('Engine Oil');
        }

        $this->actingAs($admin)->get(route('admin.suppliers', ['search' => 'Metro Parts']))
            ->assertOk()->assertSee('Chain Set')->assertSee('Engine Oil');

        $this->actingAs($admin)->get(route('admin.suppliers', ['search' => 'SUP-OIL']))
            ->assertOk()->assertSee('Engine Oil')->assertDontSee('Chain Set');
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
