<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineLearningPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_tables_paginate_without_losing_or_repeating_products(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (range(1, 55) as $number) {
            Product::create(['sku' => 'ML-PAGE-'.$number, 'name' => 'Pagination Product '.$number,
                'unit_price' => 100, 'current_stock' => 5]);
        }

        foreach ([
            ['admin.analytics', 'forecastItems', 'forecast_rows', 'forecast_page'],
            ['admin.dead-stock', 'outlookItems', 'outlook_rows', 'outlook_page'],
        ] as [$route, $variable, $size, $page]) {
            $first = $this->get(route($route))->assertOk()->viewData($variable);
            $this->assertSame(20, $first->count());
            $this->assertSame(55, $first->total());
            $second = $this->get(route($route, [$page => 2]))->assertOk()->viewData($variable);
            $this->assertSame(20, $second->count());
            $this->assertEmpty($first->pluck('sku')->intersect($second->pluck('sku'))->all());
            $this->assertSame(50, $this->get(route($route, [$size => 50]))->assertOk()->viewData($variable)->count());
            $this->assertSame(55, $this->get(route($route, [$size => 100]))->assertOk()->viewData($variable)->count());
            $this->assertSame(20, $this->get(route($route, [$size => 99999]))->assertOk()->viewData($variable)->perPage());
        }
    }
}
