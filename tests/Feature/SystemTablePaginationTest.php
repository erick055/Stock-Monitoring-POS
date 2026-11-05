<?php

namespace Tests\Feature;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\StockAlertDelivery;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemTablePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_histories_reach_older_records_and_keep_their_own_page_sizes(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'PAGING', 'name' => 'Paging Part', 'unit_price' => 100, 'current_stock' => 5]);
        $supplier = Supplier::create(['name' => 'Paging supplier', 'code' => 'PAGING']);
        foreach (range(1, 55) as $number) {
            InventoryLedger::create(['product_id' => $product->product_id, 'user_id' => $owner->id,
                'qty_in' => 1, 'qty_out' => 0, 'reason_code' => 'STOCK_IN', 'logs' => 'Record '.$number]);
            SalesTransaction::create(['staff_id' => $owner->id, 'sale_date' => now(), 'total_sale_amount' => 100, 'payment_status' => 'paid']);
            StockAlertDelivery::create(['product_id' => $product->product_id, 'channel' => 'email', 'alert_type' => 'critical',
                'status' => 'sent', 'recipient' => 'test@example.com', 'message' => 'Record '.$number]);
            SupplierImport::create(['supplier_id' => $supplier->supplier_id, 'source_filename' => 'file-'.$number.'.csv',
                'file_hash' => hash('sha256', (string) $number), 'status' => 'approved', 'uploaded_by' => $owner->id]);
        }
        $this->actingAs($owner);
        foreach ([
            ['admin.inventory', 'ledgers', 'ledger', 'movement-logs'],
            ['admin.pos', 'checkoutLogs', 'checkout', 'checkout-history'],
            ['admin.low-stocks', 'deliveries', 'delivery', 'notification-history'],
            ['admin.suppliers', 'importHistory', 'history', 'import-history'],
        ] as [$route, $variable, $prefix, $anchor]) {
            $firstResponse = $this->get(route($route))->assertOk()->assertSee('data-history-panel=', false);
            $first = $firstResponse->viewData($variable);
            $this->assertSame(20, $first->count());
            $this->assertSame(55, $first->total());
            $last = $this->get(route($route, [$prefix.'_page' => 3]))->assertOk()->viewData($variable);
            $this->assertSame(15, $last->count());
            $this->assertEmpty(array_intersect($first->getCollection()->modelKeys(), $last->getCollection()->modelKeys()));
            $this->assertStringEndsWith('#'.$anchor, $first->nextPageUrl());
            $large = $this->get(route($route, [$prefix.'_rows' => 50, 'search' => $prefix === 'ledger' ? 'Paging' : '']))->assertOk()->viewData($variable);
            $this->assertSame(50, $large->count());
            $this->assertStringContainsString($prefix.'_rows=50', $large->nextPageUrl());
        }
    }

    public function test_staff_checkout_pagination_cannot_expose_another_cashiers_receipts(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        foreach (range(1, 21) as $number) {
            SalesTransaction::create(['staff_id' => $staff->id, 'sale_date' => now(), 'total_sale_amount' => 10, 'payment_status' => 'paid']);
            SalesTransaction::create(['staff_id' => $other->id, 'sale_date' => now(), 'total_sale_amount' => 999, 'payment_status' => 'paid']);
        }
        $rows = $this->actingAs($staff)->get(route('staff.pos', ['checkout_page' => 2]))->assertOk()->viewData('checkoutLogs');
        $this->assertSame(21, $rows->total());
        $this->assertSame(1, $rows->count());
        $this->assertSame($staff->id, $rows->first()->staff_id);
    }
}
