<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\OpenAiCompatibilityAdvisor;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompatibilityController extends Controller
{
    public function index(Request $request, OpenAiCompatibilityAdvisor $advisor): View
    {
        $vehicleInput = [
            'brand' => trim((string) $request->input('brand', '')),
            'model' => trim((string) $request->input('model', '')),
            'year' => (string) $request->input('year', ''),
        ];
        $results = collect();
        $aiAdvice = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'brand' => ['required', 'string', 'max:100'],
                'model' => ['required', 'string', 'max:100'],
                'year' => ['required', 'integer', 'between:1950,'.(now()->year + 2)],
                'part_search' => ['nullable', 'string', 'max:100'],
            ]);

            $vehicleInput = [
                'brand' => trim($validated['brand']),
                'model' => trim($validated['model']),
                'year' => (string) $validated['year'],
            ];
            $search = trim((string) ($validated['part_search'] ?? ''));
            $products = Product::query()
                ->where('is_active', true)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($nested) use ($search) {
                        $nested->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('category', 'like', "%{$search}%")
                            ->orWhere('manufacturer', 'like', "%{$search}%")
                            ->orWhere('manufacturer_part_number', 'like', "%{$search}%");
                    });
                })
                ->orderByDesc('current_stock')
                ->orderBy('name')
                ->limit(max(1, (int) config('openai.max_products', 10)))
                ->get();

            if ($products->isEmpty()) {
                $aiAdvice = ['available' => true, 'summary' => 'No active inventory products matched your part search.', 'recommendations' => []];
            } else {
                $aiAdvice = $advisor->advise($vehicleInput, $products, (int) auth()->id());

                if ($aiAdvice['available']) {
                    $recommendations = collect($aiAdvice['recommendations']);
                    $results = $products
                        ->filter(fn (Product $product) => $recommendations->has($product->product_id))
                        ->map(fn (Product $product) => [
                            'product' => $product,
                            'assessment' => $recommendations->get($product->product_id),
                        ])
                        ->sortBy(fn (array $result) => [
                            $result['assessment']['rank'],
                            -$result['assessment']['confidence'],
                        ])->values();
                }
            }
        }

        $summary = [
            'recommended' => $results->whereIn('assessment.status', ['compatible', 'possible'])->count(),
            'compatible' => $results->where('assessment.status', 'compatible')->count(),
            'possible' => $results->where('assessment.status', 'possible')->count(),
            'incompatible' => $results->where('assessment.status', 'incompatible')->count(),
        ];

        $view = auth()->user()->role === 'admin' ? 'admin.compatibility' : 'staff.compatibility';

        return view($view, compact('vehicleInput', 'results', 'summary', 'aiAdvice'));
    }
}
