<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\OpenAiCompatibilityAdvisor;
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
                    'details' => ['Front brake pad application for this generation.'],
                    'sources' => ['https://example.com/catalog'],
                ]],
            ]),
        ])]);

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025,
        ])->assertOk()->assertSee('Compatible')
            ->assertSee('The part number is listed for this motorcycle generation.')
            ->assertDontSee('AI confidence')->assertDontSee('Checks required')->assertDontSee('https://example.com/catalog')->assertSee('Compatibility legend');

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
                'confidence' => 100, 'reason' => 'Not in inventory', 'details' => [], 'sources' => [],
            ]],
        ])])]);

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025,
        ])->assertOk()->assertDontSee('Invented');
    }

    public function test_relevant_honda_product_is_not_hidden_by_unrelated_high_stock_products(): void
    {
        config([
            'openai.api_key' => 'test-key',
            'openai.web_search' => false,
            'openai.max_products' => 10,
            'openai.max_recommendations' => 5,
        ]);
        $staff = User::factory()->create(['role' => 'staff']);

        for ($index = 1; $index <= 10; $index++) {
            $this->product([
                'sku' => 'YAMAHA-'.$index,
                'name' => 'Unrelated Part '.$index,
                'manufacturer' => 'Yamaha',
                'manufacturer_part_number' => 'YAM-'.$index,
                'current_stock' => 100 + $index,
            ]);
        }

        $hondaProduct = $this->product([
            'sku' => 'HONDA-CLICK-PAD',
            'name' => 'Honda Click Brake Pad',
            'manufacturer' => 'Honda',
            'manufacturer_part_number' => 'HND-CLICK-160',
            'current_stock' => 0,
        ]);

        Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
            'summary' => 'Honda inventory product assessed.',
            'recommendations' => [[
                'product_id' => $hondaProduct->product_id,
                'status' => 'possible',
                'reason' => 'The Honda candidate was included for fitment assessment.',
                'details' => [],
                'sources' => [],
            ]],
        ])])]);

        $this->actingAs($staff)->post('/staff/compatibility/ai-recommendations', [
            'brand' => 'Honda',
            'model' => 'Click 160',
            'year' => 2025,
        ])->assertOk()
            ->assertSee('Honda Click Brake Pad')
            ->assertSee('The Honda candidate was included for fitment assessment.');

        Http::assertSent(function ($request) use ($hondaProduct) {
            $context = json_decode($request['input'], true);
            $candidateIds = collect($context['inventory_products'])->pluck('product_id');

            return count($context['inventory_products']) === 5
                && $candidateIds->contains($hondaProduct->product_id)
                && str_contains($request['instructions'], 'exactly one assessment for every supplied inventory product');
        });
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

    public function test_web_search_is_bounded_and_only_retrieved_citations_are_displayed(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.web_search' => true]);
        $product = $this->product();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [
                ['type' => 'web_search_call', 'action' => ['sources' => [['url' => 'https://example.com/catalog']]]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'summary' => 'Application found in catalog.',
                    'recommendations' => [[
                        'product_id' => $product->product_id, 'status' => 'compatible',
                        'reason' => 'Catalog lists the exact year and part number.', 'details' => ['Front brake application.'],
                        'sources' => ['https://example.com/catalog', 'https://invented.example/fake', 'javascript:alert(1)'],
                    ]],
                ])]]],
            ],
        ])]);

        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->post('/staff/compatibility/ai-recommendations', ['brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025])
            ->assertOk()->assertSee('https://example.com/catalog')->assertDontSee('https://invented.example/fake')
            ->assertDontSee('javascript:')->assertSee('Web search + AI assessment')->assertDontSee('AI confidence');

        Http::assertSent(fn ($request) => $request['max_tool_calls'] === 1
            && $request['tool_choice'] === 'required' && $request['tools'][0]['search_context_size'] === 'low'
            && $request['include'] === ['web_search_call.action.sources']
            && ! in_array('confidence', $request['text']['format']['schema']['properties']['recommendations']['items']['required']));
        Http::assertSentCount(1);
    }

    public function test_cache_reuses_fitment_after_stock_price_and_vehicle_capitalization_changes(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.web_search' => false]);
        $product = $this->product();
        Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
            'summary' => 'Fitment assessment.', 'recommendations' => [[
                'product_id' => $product->product_id, 'status' => 'possible',
                'reason' => 'Same generation, variant unspecified.', 'details' => [], 'sources' => [],
            ]],
        ])])]);
        $advisor = app(OpenAiCompatibilityAdvisor::class);
        $vehicle = ['brand' => 'Honda', 'model' => 'Click 160', 'year' => '2025'];
        $this->assertTrue($advisor->advise($vehicle, collect([$product]), 1)['available']);
        $product->update(['current_stock' => 9, 'unit_price' => 400]);
        $vehicle['brand'] = 'honda';
        $this->assertTrue($advisor->advise($vehicle, collect([$product->fresh()]), 2)['available']);
        Http::assertSentCount(1);

        $product->update(['manufacturer_part_number' => 'NEW-PART']);
        $advisor->advise($vehicle, collect([$product->fresh()]), 2);
        Http::assertSentCount(2);
    }

    public function test_all_statuses_render_and_invalid_assessments_are_ignored(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.web_search' => false]);
        $recommendations = collect(['incompatible', 'unknown', 'possible', 'compatible', 'invented-status'])->map(function ($status, $index) {
            $product = $this->product(['sku' => 'PART-'.$index, 'name' => 'Part '.$status]);

            return ['product_id' => $product->product_id, 'status' => $status, 'reason' => 'Reason '.$status, 'details' => [], 'sources' => []];
        });
        Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
            'summary' => 'Mixed fitment results.', 'recommendations' => $recommendations,
        ])])]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/compatibility/ai-recommendations', ['brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025])
            ->assertOk()->assertSeeInOrder(['Part compatible', 'Part possible', 'Part unknown', 'Part incompatible'])
            ->assertSee('data-result-status="unknown"', false)->assertDontSee('Part invented-status')
            ->assertDontSee('Possible—verify')->assertDontSee('Checks required');
    }

    public function test_incomplete_response_is_not_cached_or_automatically_retried(): void
    {
        config(['openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'output_text' => '{}'])]);
        $advisor = app(OpenAiCompatibilityAdvisor::class);
        $product = $this->product();
        $vehicle = ['brand' => 'Honda', 'model' => 'Click 160', 'year' => '2025'];
        $this->assertFalse($advisor->advise($vehicle, collect([$product]), 1)['available']);
        Http::assertSentCount(1);
        $this->assertFalse($advisor->advise($vehicle, collect([$product]), 1)['available']);
        Http::assertSentCount(2);
    }

    public function test_empty_inventory_search_does_not_call_openai(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->post('/staff/compatibility/ai-recommendations', ['brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025])
            ->assertOk()->assertSee('No active inventory products matched');
        Http::assertNothingSent();
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
