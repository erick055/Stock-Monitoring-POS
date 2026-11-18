<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogScaleTest extends TestCase
{
    use RefreshDatabase;

    public static function catalogSizes(): array
    {
        return ['150 products' => [150], '500 products' => [500], '1000 products' => [1000]];
    }

    #[DataProvider('catalogSizes')]
    public function test_large_catalog_pages_search_pagination_and_checkout(int $size): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Notification::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $now = now()->subDays(120);
        $products = [];
        for ($i = 1; $i <= $size; $i++) {
            $products[] = [
                'sku' => sprintf('SCALE-%04d', $i), 'name' => sprintf('Scale Part %04d', $i),
                'category' => 'Category '.($i % 20), 'shelf_location' => 'Shelf '.($i % 30),
                'manufacturer' => 'Scale Manufacturer', 'manufacturer_part_number' => 'MP-'.$i,
                'unit_cost' => 50 + $i, 'unit_price' => 100 + $i, 'current_stock' => [0, 5, 15, 50][$i % 4],
                'reorder_level' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($products, 100) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $promotions = [];
        for ($i = 5; $i <= $size; $i += 25) {
            $promotions[] = ['product_id' => $i, 'applied_by' => $owner->id, 'action_type' => 'discount',
                'discount_percent' => 10, 'original_price' => 100 + $i, 'promotional_price' => round((100 + $i) * .9, 2),
                'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('product_promotions')->insert($promotions);
        for ($i = 1; $i <= $size; $i += 10) {
            $sale = SalesTransaction::create(['staff_id' => $staff->id, 'payment_status' => 'paid',
                'subtotal' => (100 + $i) * 3, 'total_sale_amount' => (100 + $i) * 3, 'sale_date' => now()->subDays($i % 60)]);
            DB::table('sales_items')->insert(['sale_id' => $sale->sale_id, 'product_id' => $i,
                'quantity' => 3, 'unit_sale_price' => 100 + $i, 'unit_cost' => 50 + $i,
                'line_total' => (100 + $i) * 3, 'created_at' => now(), 'updated_at' => now()]);
        }

        $measurements = [];
        $this->actingAs($owner);
        foreach (['/admin/dashboard', '/admin/products', '/admin/inventory', '/admin/pos', '/admin/analytics',
            '/admin/analytics?period=month', '/admin/analytics?period=year', '/admin/deadstock',
            '/admin/low-stocks', '/admin/returns', '/admin/compatibility', '/admin/suppliers', '/inventory/live'] as $path) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $started = hrtime(true);
            $response = $this->get($path)->assertOk();
            $measurements[$path] = ['ms' => round((hrtime(true) - $started) / 1e6, 1),
                'queries' => count(DB::getQueryLog()), 'bytes' => strlen($response->getContent())];
            DB::disableQueryLog();
        }

        $target = sprintf('SCALE-%04d', $size);
        $this->get('/admin/products?search='.$target)->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === 1 && $rows->first()->sku === $target);
        $this->get('/admin/products?page='.ceil($size / 20))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === $size && $rows->count() === (($size - 1) % 20) + 1);
        $this->get('/admin/inventory?search='.$target)->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->count() === 1 && $rows->first()->sku === $target);
        $this->get('/admin/inventory')->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === $size && $rows->count() === 20)
            ->assertViewHas('allProducts', fn ($rows) => $rows->count() === $size);
        $this->get('/admin/inventory?per_page=50&page='.ceil($size / 50))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === $size && $rows->count() === 50);
        $this->get('/admin/inventory?status=healthy&per_page=20')->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === intdiv($size + 1, 4)
                && $rows->every(fn ($product) => $product->stock_status === 'healthy'));
        $this->get('/admin/deadstock?per_page=20&page=2')->assertOk()
            ->assertViewHas('riskItems', fn ($rows) => $rows->total() > 100 && $rows->count() === 20);
        $this->get('/admin/low-stocks?per_page=20&page=2')->assertOk()
            ->assertViewHas('activeAlerts', fn ($rows) => $rows->total() > 100 && $rows->count() === 20);
        $this->actingAs($staff)->get('/staff/products')->assertOk();
        $this->get('/staff/pos')->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->count() === $size - intdiv($size, 4));
        $lastStocked = Product::where('current_stock', '>', 0)->orderByDesc('product_id')->firstOrFail();
        $beforeStock = $lastStocked->current_stock;
        $saleCount = SalesTransaction::count();
        $payload = ['items' => [['product_id' => $lastStocked->product_id, 'quantity' => 1]]];
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $checkout = $this->postJson(route('staff.pos.checkout'), ['payment_method' => 'cash', 'amount_tendered' => 10000000, ...$payload])->assertCreated();
        $this->postJson(route('staff.pos.checkout'), ['payment_method' => 'cash', 'amount_tendered' => 10000000, ...$payload])->assertCreated()->assertJsonPath('sale_id', $checkout->json('sale_id'));
        $this->assertSame($beforeStock - 1, $lastStocked->fresh()->current_stock);
        $this->assertSame($saleCount + 1, SalesTransaction::count());
        $this->get($checkout->json('receipt.url'))->assertOk()->assertSee($lastStocked->name);
        foreach ($measurements as $path => $measurement) {
            $this->assertLessThan(100, $measurement['queries'], "$path uses too many queries with $size products.");
        }
        $this->assertLessThan(700 * 1024, $measurements['/admin/inventory']['bytes'], 'Inventory should not render the entire catalog as table rows.');
        $directory = storage_path('app/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/catalog-scale-'.$size.'.json', json_encode($measurements, JSON_PRETTY_PRINT));
    }
}
