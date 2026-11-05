<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use App\Support\Display;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosDisplayConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_formats_money_and_new_receipt_rows_match_the_reloaded_template(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        SalesTransaction::create(['staff_id' => $owner->id, 'payment_status' => 'paid', 'payment_method' => 'cash', 'total_sale_amount' => 2000, 'sale_date' => now()]);
        $this->actingAs($owner)->get(route('admin.pos'))->assertOk()->assertSee('₱2,000.00')->assertDontSee('UI preview');
        $product = Product::create(['sku' => 'B15', 'name' => 'Display part', 'unit_price' => 2000, 'current_stock' => 2]);
        $checkout = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson(route('admin.pos.checkout'), [
            'items' => [['product_id' => $product->product_id, 'quantity' => 1]], 'payment_method' => 'cash', 'amount_tendered' => 2000,
        ])->assertCreated();
        $row = $checkout->json('receipt.row_html');
        $this->assertIsString($row);
        $this->assertStringContainsString('₱2,000.00', $row);
        $this->assertStringContainsString('1 unit', $row);
        $this->assertStringNotContainsString('#POS-', $row);
        $page = $this->get(route('admin.pos'))->assertOk()->getContent();
        $this->assertStringContainsString(trim($row), $page);
        $this->get($checkout->json('receipt.url'))->assertOk()->assertSee('₱2,000.00');
        $this->get(route('admin.products'))->assertOk()->assertSee('>1 unit<', false);
    }

    public function test_shared_formatters_handle_separators_negative_money_and_plural_counts(): void
    {
        $this->assertSame('₱1,234.56', Display::money(1234.56));
        $this->assertSame('-₱1,234.56', Display::money(-1234.56));
        $this->assertSame('₱0.00', Display::money(0));
        $this->assertSame('1 unit', Display::count(1, 'unit'));
        $this->assertSame('0 units', Display::count(0, 'unit'));
        $this->assertSame('1,000 units', Display::count(1000, 'unit'));
        $this->assertSame('1.5 units', Display::count(1.5, 'unit', null, 1));
    }
}
