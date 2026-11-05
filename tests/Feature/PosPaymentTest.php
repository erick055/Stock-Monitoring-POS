<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_without_payment_details_cannot_save_a_paid_sale(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'B4', 'name' => 'Payment part', 'unit_price' => 800, 'current_stock' => 5]);
        $this->actingAs($staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('staff.pos.checkout'), ['items' => [['product_id' => $product->product_id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertSame(5, $product->fresh()->current_stock);
    }

    public function test_confirmed_cash_payment_stores_tendered_change_and_is_idempotent(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'B4-CASH', 'name' => 'Cash part', 'unit_price' => 800, 'current_stock' => 5]);
        $payload = ['items' => [['product_id' => $product->product_id, 'quantity' => 1]], 'labor_amount' => 150, 'payment_method' => 'cash', 'amount_tendered' => 1000];
        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid());
        $first = $this->postJson(route('admin.pos.checkout'), $payload)->assertCreated()
            ->assertJsonPath('receipt.payment_method', 'Cash')->assertJsonPath('receipt.amount_tendered', 1000)
            ->assertJsonPath('receipt.change_due', 50)->assertJsonPath('receipt.total', 950);
        $this->postJson(route('admin.pos.checkout'), $payload)->assertCreated()->assertJsonPath('sale_id', $first->json('sale_id'));
        $this->assertDatabaseCount('sales_transactions', 1);
        $this->assertSame(4, $product->fresh()->current_stock);
        $this->assertDatabaseHas('sales_transactions', ['sale_id' => $first->json('sale_id'), 'payment_method' => 'cash', 'amount_tendered' => 1000, 'change_due' => 50]);
        $this->get($first->json('receipt.url'))->assertOk()->assertSee('Cash tendered')->assertSee('Change due')->assertSee('₱50.00');
        $payload['amount_tendered'] = 1100;
        $this->postJson(route('admin.pos.checkout'), $payload)->assertConflict();
        $this->assertDatabaseCount('sales_transactions', 1);
    }

    public function test_insufficient_cash_rolls_back_stock_and_does_not_trust_client_total_or_change(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'B4-SHORT', 'name' => 'Short payment', 'unit_price' => 800, 'current_stock' => 5]);
        $this->actingAs($staff)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('staff.pos.checkout'), ['items' => [['product_id' => $product->product_id, 'quantity' => 1]], 'labor_amount' => 150,
                'payment_method' => 'cash', 'amount_tendered' => 949.99, 'payment_total' => 1, 'change_due' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_tendered');
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertDatabaseCount('sales_transactions', 0);
        $this->assertDatabaseCount('inventory_ledgers', 0);
    }

    public function test_gcash_maya_and_card_require_and_store_references(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $product = Product::create(['sku' => 'B4-DIGITAL', 'name' => 'Digital payment', 'unit_price' => 800, 'current_stock' => 5]);
        foreach (['gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card'] as $method => $label) {
            $payload = ['items' => [['product_id' => $product->product_id, 'quantity' => 1]], 'payment_method' => $method];
            $this->actingAs($staff)->withHeader('Idempotency-Key', (string) Str::uuid());
            $this->postJson(route('staff.pos.checkout'), $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
            $this->postJson(route('staff.pos.checkout'), [...$payload, 'payment_reference' => '  REF-'.$method.'  ', 'change_due' => 999])->assertCreated()
                ->assertJsonPath('receipt.payment_method', $label)->assertJsonPath('receipt.payment_reference', 'REF-'.$method)
                ->assertJsonPath('receipt.amount_tendered', null)->assertJsonPath('receipt.change_due', 0);
            $this->assertDatabaseHas('sales_transactions', ['payment_method' => $method, 'payment_reference' => 'REF-'.$method, 'change_due' => 0]);
        }
        $this->assertDatabaseCount('sales_transactions', 3);
        $this->assertSame(2, $product->fresh()->current_stock);
    }

    public function test_payment_dialog_is_available_to_both_roles_without_creating_a_sale(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route($role.'.pos'))->assertOk()
                ->assertSee('data-payment-dialog', false)->assertSee('Confirm payment')->assertSee('Cash tendered')
                ->assertSee('GCash')->assertSee('Maya')->assertSee('Card')->assertSee('Payment reference number');
        }
        $this->assertDatabaseCount('sales_transactions', 0);
    }
}
