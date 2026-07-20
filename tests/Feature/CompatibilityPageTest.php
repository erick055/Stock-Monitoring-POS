<?php

namespace Tests\Feature;

use App\Models\Motorcycle;
use App\Models\PartCompatibility;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompatibilityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_staff_can_view_the_checker_but_guests_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)
            ->get('/admin/compatibility')
            ->assertOk()
            ->assertSee('AI Motorcycle Parts Compatibility Checker')
            ->assertSee('Enter motorcycle details')
            ->assertSee('Structured Fitment Data')
            ->assertSee('Add motorcycle profile')
            ->assertSee('Add product technical profile')
            ->assertSee('Verify product fitment');

        $this->actingAs($staff)
            ->get('/staff/compatibility')
            ->assertOk()
            ->assertSee('AI Motorcycle Parts Compatibility Checker')
            ->assertSee('Enter motorcycle details')
            ->assertDontSee('Structured Fitment Data');

        auth()->logout();
        $this->get('/admin/compatibility')->assertRedirect('/');
    }

    public function test_staff_cannot_use_admin_fitment_management(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->post('/admin/compatibility/motorcycles', [])
            ->assertForbidden();
    }

    public function test_admin_can_register_structured_motorcycle_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/compatibility/motorcycles', [
            'brand' => 'Honda',
            'model' => 'Click 160',
            'year' => 2025,
            'engine' => '157cc',
            'variant' => 'ABS',
            'motorcycle_specifications' => "spark_plug_thread=M10\nbrake_pad_shape=A12",
            'motorcycle_features' => "ABS\nfuel injection",
        ])->assertRedirect();

        $motorcycle = Motorcycle::firstOrFail();
        $this->assertSame('M10', $motorcycle->specifications['spark_plug_thread']);
        $this->assertContains('ABS', $motorcycle->features);
    }

    public function test_verified_exact_fit_is_shown_as_confirmed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $motorcycle = $this->motorcycle();
        $product = $this->product();

        PartCompatibility::create([
            'product_id' => $product->product_id,
            'motorcycle_id' => $motorcycle->motorcycle_id,
            'compatibility_status' => 'exact_fit',
            'reasons' => ['Manufacturer catalog lists this exact model and year.'],
            'source_reference' => 'Honda parts catalog HC-2025 page 42',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get($this->searchUrl('/admin/compatibility', $motorcycle))
            ->assertOk()
            ->assertSee('Confirmed fit')
            ->assertSee('Manufacturer catalog lists this exact model and year.')
            ->assertSee('Verified fitment record');
    }

    public function test_unverified_record_never_becomes_confirmed(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $motorcycle = $this->motorcycle();
        $product = $this->product();

        PartCompatibility::create([
            'product_id' => $product->product_id,
            'motorcycle_id' => $motorcycle->motorcycle_id,
            'compatibility_status' => 'exact_fit',
            'reasons' => ['Seller description claims fitment.'],
        ]);

        $this->actingAs($staff)
            ->get($this->searchUrl('/staff/compatibility', $motorcycle))
            ->assertOk()
            ->assertSee('Possible fit—verify')
            ->assertSee('Unverified fitment record');
    }

    public function test_catalog_motorcycle_recommends_all_supported_inventory_with_price_stock_and_reference(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $motorcycle = $this->motorcycle(['specifications' => ['spark_plug_thread' => 'M10']]);
        $confirmed = $this->product([
            'sku' => 'CONFIRMED-001',
            'name' => 'Confirmed Brake Pad',
            'unit_price' => 425.50,
            'current_stock' => 8,
        ]);
        $this->product([
            'sku' => 'POSSIBLE-001',
            'name' => 'Possible Spark Plug',
            'category' => 'Ignition',
            'specifications' => ['spark_plug_thread' => 'M10'],
            'unit_price' => 180,
            'current_stock' => 0,
        ]);

        PartCompatibility::create([
            'product_id' => $confirmed->product_id,
            'motorcycle_id' => $motorcycle->motorcycle_id,
            'compatibility_status' => 'exact_fit',
            'reasons' => ['Exact catalog match.'],
            'source_reference' => 'Honda catalog page 42',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/compatibility?'.http_build_query([
                'vehicle_search' => 1,
                'motorcycle_id' => $motorcycle->motorcycle_id,
            ]))
            ->assertOk()
            ->assertSee('Compatible parts for this motorcycle')
            ->assertSee('Confirmed Brake Pad')
            ->assertSee('Possible Spark Plug')
            ->assertSee('Honda catalog page 42')
            ->assertSee('₱425.50')
            ->assertSee('8 in stock')
            ->assertSee('Out of stock')
            ->assertSee('data-result-filter="recommended"', false)
            ->assertSee('data-recommended="true"', false);
    }

    public function test_structured_specification_mismatch_is_explained(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $motorcycle = $this->motorcycle(['specifications' => ['spark_plug_thread' => 'M10']]);
        $product = $this->product(['specifications' => ['spark_plug_thread' => 'M12']]);

        $this->actingAs($admin)
            ->get($this->searchUrl('/admin/compatibility', $motorcycle))
            ->assertOk()
            ->assertSee('Not compatible')
            ->assertSee('Spark plug thread requires M12; motorcycle record has M10.')
            ->assertSee('Structured technical rules');
    }

    public function test_admin_can_save_a_verified_fitment_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $motorcycle = $this->motorcycle();
        $product = $this->product();

        $this->actingAs($admin)->post('/admin/compatibility/fitments', [
            'product_id' => $product->product_id,
            'motorcycle_id' => $motorcycle->motorcycle_id,
            'compatibility_status' => 'conditional_fit',
            'fitment_notes' => 'Fits after measuring the bracket.',
            'reasons' => 'Mounting points match',
            'conditions' => 'Use manufacturer bracket B-12',
            'source_reference' => 'Supplier technical bulletin 2026-04',
        ])->assertRedirect();

        $record = PartCompatibility::firstOrFail();
        $this->assertSame('conditional_fit', $record->compatibility_status);
        $this->assertSame($admin->id, $record->verified_by);
        $this->assertNotNull($record->verified_at);
    }

    public function test_free_form_search_does_not_register_a_customer_vehicle(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->product([
            'description' => 'Replacement brake pad suitable for Honda Click 160 motorcycles.',
        ]);

        $url = '/staff/compatibility?'.http_build_query([
            'vehicle_search' => 1,
            'brand' => 'Honda',
            'model' => 'Click 160',
            'year' => 2025,
            'engine' => '157cc',
            'variant' => 'ABS',
        ]);

        $this->actingAs($staff)
            ->get($url)
            ->assertOk()
            ->assertSee('No exact reference profile was found')
            ->assertSee('Possible fit—verify')
            ->assertSee('AI-assisted description interpretation');

        $this->assertDatabaseCount('motorcycles', 0);
    }

    private function searchUrl(string $path, Motorcycle $motorcycle): string
    {
        return $path.'?'.http_build_query([
            'vehicle_search' => 1,
            'brand' => $motorcycle->brand,
            'model' => $motorcycle->model,
            'year' => $motorcycle->year,
            'engine' => $motorcycle->engine,
            'variant' => $motorcycle->variant,
        ]);
    }

    private function motorcycle(array $attributes = []): Motorcycle
    {
        return Motorcycle::create(array_merge([
            'brand' => 'Honda',
            'model' => 'Click 160',
            'year' => 2025,
            'engine' => '157cc',
            'variant' => 'ABS',
            'specifications' => [],
            'features' => ['ABS'],
        ], $attributes));
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'TEST-PART-001',
            'name' => 'Test Brake Pad',
            'description' => null,
            'category' => 'Brakes',
            'unit_cost' => 200,
            'unit_price' => 350,
            'current_stock' => 10,
            'reorder_level' => 3,
            'is_active' => true,
        ], $attributes));
    }
}
