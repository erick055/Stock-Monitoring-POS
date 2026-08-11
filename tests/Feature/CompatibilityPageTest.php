<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompatibilityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_staff_can_view_ai_checker_but_guests_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get('/admin/compatibility')->assertOk()
            ->assertSee('AI Motorcycle Parts Compatibility')->assertSee('Powered by OpenAI');
        $this->actingAs($staff)->get('/staff/compatibility')->assertOk()
            ->assertSee('Ask AI for Recommendations');
        auth()->logout();
        $this->get('/admin/compatibility')->assertRedirect('/');
    }

    public function test_legacy_compatibility_storage_is_removed(): void
    {
        $this->assertFalse(Schema::hasTable('motorcycles'));
        $this->assertFalse(Schema::hasTable('part_compatibilities'));
        $this->assertFalse(Schema::hasColumn('products', 'supported_vehicles'));
        $this->assertFalse(Schema::hasColumn('products', 'fitment_source'));
        $this->assertFalse(Schema::hasColumn('products', 'specifications'));
    }

    public function test_search_is_generated_by_openai_from_typed_vehicle_and_core_inventory_only(): void
    {
        config([
            'openai.api_key' => 'test-key',
            'openai.model' => 'test-model',
            'openai.web_search' => false,
            'openai.reasoning_effort' => 'none',
            'openai.max_output_tokens' => 1200,
        ]);
        $staff = User::factory()->create(['role' => 'staff']);
        $product = $this->product();
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_test', 'model' => 'test-model',
            'output_text' => json_encode([
                'summary' => 'One inventory part is a likely match.',
                'recommendations' => [[
                    'product_id' => $product->product_id, 'rank' => 1,
                    'status' => 'compatible', 'label' => 'Likely compatible', 'confidence' => 88,
                    'reason' => 'The part number is listed for this motorcycle generation.',
                    'checks' => ['Confirm the exact variant before installation.'],
                    'sources' => ['https://example.com/catalog'],
                ]],
            ]),
        ])]);

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025,
        ])->assertOk()->assertSee('Likely compatible')
            ->assertSee('The part number is listed for this motorcycle generation.')
            ->assertSee('AI confidence: 88%')->assertSee('https://example.com/catalog');

        Http::assertSent(function ($request) use ($product) {
            $context = json_decode($request['input'], true);
            $sentProduct = $context['inventory_products'][0];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['store'] === false
                && ! isset($request['tools'])
                && $request['reasoning']['effort'] === 'none'
                && $request['max_output_tokens'] === 1200
                && str_starts_with($request['safety_identifier'], 'motosync_')
                && strlen($request['safety_identifier']) <= 64
                && $request['text']['format']['type'] === 'json_schema'
                && $context['motorcycle'] === ['brand' => 'Honda', 'model' => 'Click 160', 'year' => '2025']
                && $sentProduct['product_id'] === $product->product_id
                && ! array_key_exists('supported_vehicles', $sentProduct)
                && ! array_key_exists('rule_assessment', $sentProduct);
        });
    }

    public function test_ai_cannot_invent_inventory_products(): void
    {
        config(['openai.enabled' => true, 'openai.api_key' => 'test-key']);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->product();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['output_text' => json_encode([
            'summary' => 'Result', 'recommendations' => [[
                'product_id' => 999999, 'rank' => 1, 'status' => 'compatible', 'label' => 'Invented',
                'confidence' => 100, 'reason' => 'Not in inventory', 'checks' => [], 'sources' => [],
            ]],
        ])])]);

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025,
        ])->assertOk()->assertDontSee('Invented');
    }

    public function test_api_failure_does_not_fall_back_to_database_rules(): void
    {
        config(['openai.enabled' => true, 'openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 500)]);
        $staff = User::factory()->create(['role' => 'staff']);
        $this->product();

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025,
        ])->assertOk()->assertSee('AI recommendations are temporarily unavailable')->assertDontSee('Test Brake Pad');
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'TEST-PART-001', 'name' => 'Test Brake Pad', 'manufacturer' => 'Honda',
            'manufacturer_part_number' => '06455-K2S-N01', 'description' => 'Front brake pad set',
            'category' => 'Brakes', 'unit_cost' => 200, 'unit_price' => 350,
            'current_stock' => 10, 'reorder_level' => 3, 'is_active' => true,
        ], $attributes));
    }
}
