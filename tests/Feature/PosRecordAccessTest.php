<?php

namespace Tests\Feature;

use App\Models\HeldOrder;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid());
    }

    public function test_staff_lists_and_receipt_access_are_scoped_to_their_own_records(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ownSale = $this->sale($staff);
        $otherSale = $this->sale($other);
        $ownHold = HeldOrder::create(['staff_id' => $staff->id, 'status' => 'held', 'held_at' => now()]);
        $otherHold = HeldOrder::create(['staff_id' => $other->id, 'status' => 'held', 'held_at' => now()]);

        $this->actingAs($staff)->get(route('staff.pos'))->assertOk()
            ->assertViewHas('checkoutLogs', fn ($rows) => $rows->count() === 1 && $rows->first()->sale_id === $ownSale->sale_id)
            ->assertViewHas('heldOrders', fn ($rows) => $rows->count() === 1 && $rows->first()['id'] === $ownHold->held_order_id);
        $this->get(route('staff.pos.receipts.show', $ownSale))->assertOk();
        $this->get(route('staff.pos.receipts.show', $otherSale))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.pos'))->assertOk()
            ->assertViewHas('checkoutLogs', fn ($rows) => $rows->count() === 2)
            ->assertViewHas('heldOrders', fn ($rows) => $rows->count() === 2);
        $this->get(route('admin.pos.receipts.show', $otherSale))->assertOk();
    }

    public function test_staff_cannot_cancel_or_complete_another_cashiers_hold_but_owner_can(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $other = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'ACCESS-TEST', 'name' => 'Access Test Part', 'unit_price' => 100, 'current_stock' => 5]);
        $hold = HeldOrder::create(['staff_id' => $other->id, 'status' => 'held', 'held_at' => now()]);
        $payload = ['held_order_id' => $hold->held_order_id, 'items' => [['product_id' => $product->product_id, 'quantity' => 1]]];

        $this->actingAs($staff)->deleteJson(route('staff.pos.holds.cancel', $hold))->assertForbidden();
        $this->postJson(route('staff.pos.checkout'), $payload)->assertForbidden();
        $this->assertSame('held', $hold->fresh()->status);
        $this->assertSame(5, $product->fresh()->current_stock);
        $this->assertDatabaseCount('sales_transactions', 0);

        $this->actingAs($admin)->postJson(route('admin.pos.checkout'), $payload)->assertCreated();
        $this->assertSame('completed', $hold->fresh()->status);
        $this->assertSame(4, $product->fresh()->current_stock);
        $anotherHold = HeldOrder::create(['staff_id' => $other->id, 'status' => 'held', 'held_at' => now()]);
        $this->deleteJson(route('admin.pos.holds.cancel', $anotherHold))->assertOk();
        $this->assertSame('cancelled', $anotherHold->fresh()->status);
    }

    private function sale(User $staff): SalesTransaction
    {
        return SalesTransaction::create(['staff_id' => $staff->id, 'subtotal' => 100, 'tax_amount' => 0,
            'total_sale_amount' => 100, 'payment_status' => 'paid', 'sale_date' => now()]);
    }
}
