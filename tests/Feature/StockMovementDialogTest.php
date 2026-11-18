<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_page_offers_three_movement_choices_and_keeps_the_form_in_a_closed_dialog(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($owner)->get(route('admin.inventory'))->assertOk();
        foreach (['in', 'out', 'adjustment'] as $type) {
            $response->assertSee('data-open-movement="'.$type.'"', false);
        }
        $response->assertSee('data-movement-dialog data-open-on-error="false"', false)
            ->assertSee('motosync-theme');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//dialog[@data-movement-dialog]//form[@data-movement-form]')->length);
        $this->assertSame(0, $xpath->query('//dialog[@data-movement-dialog][@open]')->length);
    }

    public function test_invalid_movement_reopens_the_dialog_and_retains_the_chosen_type(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner)->from(route('admin.inventory'))->post(route('admin.inventory.movements.store'), [
            'movement_type' => 'out', 'quantity' => 5, 'reason_code' => 'DAMAGED',
        ])->assertSessionHasErrors('product_id');
        $response = $this->get(route('admin.inventory'))->assertOk()
            ->assertSee('data-movement-dialog data-open-on-error="true"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//select[@name="movement_type"]/option[@value="out"][@selected]')->length);
    }
}
