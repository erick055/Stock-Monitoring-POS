<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompatibilityRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = ['brand' => 'Honda', 'model' => 'Click 160', 'year' => 2025, 'part_search' => 'Brake'];

    public function test_background_checks_do_not_use_the_compatibility_search_allowance(): void
    {
        Http::fake();
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner);
        for ($i = 0; $i < 15; $i++) $this->getJson(route('session.status'))->assertOk();
        for ($i = 0; $i < 10; $i++) $this->post(route('admin.compatibility.ai'), self::SEARCH)->assertOk();
        Http::assertNothingSent();
    }

    public function test_real_limit_returns_to_the_form_with_input_and_a_retry_message(): void
    {
        Http::fake();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);
        for ($i = 0; $i < 10; $i++) $this->post(route('staff.compatibility.ai'), self::SEARCH)->assertOk();
        $this->post(route('staff.compatibility.ai'), self::SEARCH)
            ->assertRedirect(route('staff.compatibility'))->assertSessionHasErrors('compatibility')
            ->assertSessionHasInput('brand', 'Honda')->assertSessionHasInput('model', 'Click 160');
        $this->get(route('staff.compatibility'))->assertOk()->assertSee('before another compatibility search')
            ->assertSee('value="Honda"', false)->assertSee('value="Click 160"', false)->assertSee('value="Brake"', false);
        $this->travel(61)->seconds();
        $this->post(route('staff.compatibility.ai'), self::SEARCH)->assertOk();
    }

    public function test_limits_are_per_account_and_json_responses_keep_retry_information(): void
    {
        Http::fake();
        $first = User::factory()->create(['role' => 'staff']);
        $second = User::factory()->create(['role' => 'staff']);
        $this->actingAs($first);
        for ($i = 0; $i < 10; $i++) $this->post(route('staff.compatibility.ai'), self::SEARCH)->assertOk();
        $this->postJson(route('staff.compatibility.ai'), self::SEARCH)->assertStatus(429)
            ->assertHeader('Retry-After')->assertJsonStructure(['message', 'retry_after']);
        $this->actingAs($second)->post(route('staff.compatibility.ai'), self::SEARCH)->assertOk();
    }

    public function test_repeated_searches_reuse_the_ai_cache(): void
    {
        config(['openai.api_key' => 'test-key', 'openai.web_search' => false]);
        $owner = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'THROTTLE-BRAKE', 'name' => 'Brake Pad', 'current_stock' => 5]);
        Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
            'summary' => 'Cached assessment.', 'recommendations' => [[
                'product_id' => $product->product_id, 'status' => 'unknown', 'reason' => 'No exact fitment data.', 'details' => [], 'sources' => [],
            ]],
        ])])]);
        $this->actingAs($owner);
        for ($i = 0; $i < 5; $i++) $this->post(route('admin.compatibility.ai'), self::SEARCH)->assertOk()->assertSee('Cached assessment.');
        Http::assertSentCount(1);
    }
}
