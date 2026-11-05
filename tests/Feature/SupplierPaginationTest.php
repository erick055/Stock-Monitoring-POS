<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_page_sizes_preserve_filters_and_totals_across_all_results(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::create(['name' => 'Pagination Supplier', 'code' => 'PAGINATION']);
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = [
                'supplier_id' => $supplier->supplier_id, 'supplier_sku' => sprintf('PAGE-%03d', $i),
                'product_name' => sprintf('Part %03d', $i), 'unit_price' => 10, 'previous_price' => 5,
                'source_filename' => 'pagination.csv',
                'last_updated_at' => now()->subDays(31), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('supplier_prices')->insert($rows);
        $this->actingAs($owner);
        foreach ([20, 50, 100] as $size) {
            $response = $this->get(route('admin.suppliers', ['per_page' => $size, 'sort' => 'product', 'search' => 'Part']))->assertOk();
            $prices = $response->viewData('prices');
            $this->assertSame($size, $prices->count());
            $this->assertSame(101, $prices->total());
            $this->assertSame(101, $response->viewData('summary')['prices']);
            $this->assertSame(101, $response->viewData('summary')['changes']);
            $this->assertSame(101, $response->viewData('summary')['stale']);
            parse_str(parse_url($prices->nextPageUrl(), PHP_URL_QUERY), $query);
            $this->assertSame((string) $size, $query['per_page']);
            $this->assertSame('product', $query['sort']);
            $this->assertSame('Part', $query['search']);
            $this->assertSame('2', $query['prices_page']);
        }
        $response = $this->get(route('admin.suppliers', ['per_page' => 100, 'prices_page' => 2, 'sort' => 'product', 'search' => 'Part']))->assertOk();
        $this->assertSame(1, $response->viewData('prices')->count());
        $this->assertSame('Part 101', $response->viewData('prices')->first()->product_name);
        $this->assertSame(101, $response->viewData('summary')['changes']);
        $response = $this->get(route('admin.suppliers', ['per_page' => 99999]))->assertOk();
        $this->assertSame(20, $response->viewData('prices')->count());
    }
}
