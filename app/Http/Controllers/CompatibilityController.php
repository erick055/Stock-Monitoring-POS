<?php

namespace App\Http\Controllers;

use App\Models\Motorcycle;
use App\Models\PartCompatibility;
use App\Models\Product;
use App\Services\PartCompatibilityChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompatibilityController extends Controller
{
    public function index(Request $request, PartCompatibilityChecker $checker): View
    {
        $motorcycles = Motorcycle::query()
            ->orderBy('brand')
            ->orderBy('model')
            ->orderByDesc('year')
            ->get();
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedMotorcycle = null;
        $catalogMatched = false;
        $results = collect();
        $vehicleInput = [
            'motorcycle_id' => (string) $request->query('motorcycle_id', ''),
            'brand' => (string) $request->query('brand', ''),
            'model' => (string) $request->query('model', ''),
            'year' => (string) $request->query('year', ''),
            'engine' => (string) $request->query('engine', ''),
            'variant' => (string) $request->query('variant', ''),
        ];

        if ($request->boolean('vehicle_search')) {
            $validated = $request->validate([
                'motorcycle_id' => ['nullable', 'integer', 'exists:motorcycles,motorcycle_id'],
                'brand' => ['required_without:motorcycle_id', 'nullable', 'string', 'max:100'],
                'model' => ['required_without:motorcycle_id', 'nullable', 'string', 'max:100'],
                'year' => ['required_without:motorcycle_id', 'nullable', 'integer', 'between:1950,'.(now()->year + 2)],
                'engine' => ['required_without:motorcycle_id', 'nullable', 'string', 'max:100'],
                'variant' => ['nullable', 'string', 'max:100'],
                'part_search' => ['nullable', 'string', 'max:100'],
            ]);

            $catalogMotorcycle = filled($validated['motorcycle_id'] ?? null)
                ? Motorcycle::with('compatibilities')->findOrFail($validated['motorcycle_id'])
                : null;

            if (! $catalogMotorcycle) {
                $vehicleInput = [
                    'motorcycle_id' => '',
                    'brand' => trim((string) $validated['brand']),
                    'model' => trim((string) $validated['model']),
                    'year' => (string) $validated['year'],
                    'engine' => trim((string) $validated['engine']),
                    'variant' => trim($validated['variant'] ?? ''),
                ];

                $catalogCandidates = Motorcycle::query()
                    ->with('compatibilities')
                    ->whereRaw('LOWER(brand) = ?', [mb_strtolower($vehicleInput['brand'])])
                    ->whereRaw('LOWER(model) = ?', [mb_strtolower($vehicleInput['model'])])
                    ->where('year', $validated['year'])
                    ->whereRaw('LOWER(engine) = ?', [mb_strtolower($vehicleInput['engine'])])
                    ->when($vehicleInput['variant'] !== '', fn ($query) => $query->whereRaw('LOWER(variant) = ?', [mb_strtolower($vehicleInput['variant'])]))
                    ->get();

                // If several variants exist and none was entered, do not guess one.
                $catalogMotorcycle = $catalogCandidates->count() === 1 ? $catalogCandidates->first() : null;
            } else {
                $vehicleInput = [
                    'motorcycle_id' => (string) $catalogMotorcycle->motorcycle_id,
                    'brand' => $catalogMotorcycle->brand,
                    'model' => $catalogMotorcycle->model,
                    'year' => (string) $catalogMotorcycle->year,
                    'engine' => $catalogMotorcycle->engine,
                    'variant' => $catalogMotorcycle->variant ?? '',
                ];
            }

            $catalogMatched = $catalogMotorcycle !== null;

            $selectedMotorcycle = $catalogMotorcycle ?: new Motorcycle([
                'brand' => $vehicleInput['brand'],
                'model' => $vehicleInput['model'],
                'year' => (int) $vehicleInput['year'],
                'engine' => $vehicleInput['engine'],
                'variant' => $vehicleInput['variant'] ?: null,
                'specifications' => [],
                'features' => [],
            ]);

            $records = $catalogMotorcycle
                ? $catalogMotorcycle->compatibilities->keyBy('product_id')
                : collect();
            $search = trim((string) ($validated['part_search'] ?? ''));

            $matchingProducts = Product::query()
                ->where('is_active', true)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($nested) use ($search) {
                        $nested->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('category', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->get();

            $results = $matchingProducts->map(function (Product $product) use ($checker, $selectedMotorcycle, $records) {
                return [
                    'product' => $product,
                    'assessment' => $checker->check($product, $selectedMotorcycle, $records->get($product->product_id)),
                ];
            })->sortBy(fn ($result) => [
                match ($result['assessment']['code']) {
                    'confirmed' => 0,
                    'possible' => 1,
                    'unverified' => 2,
                    'incompatible' => 3,
                    default => 4,
                },
                $result['product']->current_stock > 0 ? 0 : 1,
                mb_strtolower($result['product']->name),
            ])->values();
        }

        $summary = [
            'recommended' => $results->whereIn('assessment.code', ['confirmed', 'possible'])->count(),
            'confirmed' => $results->where('assessment.code', 'confirmed')->count(),
            'possible' => $results->where('assessment.code', 'possible')->count(),
            'unverified' => $results->where('assessment.code', 'unverified')->count(),
            'incompatible' => $results->where('assessment.code', 'incompatible')->count(),
        ];

        $view = auth()->user()->role === 'admin' ? 'admin.compatibility' : 'staff.compatibility';

        return view($view, compact('motorcycles', 'products', 'selectedMotorcycle', 'catalogMatched', 'vehicleInput', 'results', 'summary'));
    }

    public function storeMotorcycle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'between:1950,'.(now()->year + 2)],
            'engine' => ['required', 'string', 'max:100'],
            'variant' => ['nullable', 'string', 'max:100'],
            'motorcycle_specifications' => ['nullable', 'string', 'max:5000'],
            'motorcycle_features' => ['nullable', 'string', 'max:3000'],
        ]);

        Motorcycle::create([
            'brand' => trim($validated['brand']),
            'model' => trim($validated['model']),
            'year' => $validated['year'],
            'engine' => trim($validated['engine']),
            'variant' => filled($validated['variant'] ?? null) ? trim($validated['variant']) : null,
            'specifications' => $this->parseSpecifications($validated['motorcycle_specifications'] ?? ''),
            'features' => $this->parseList($validated['motorcycle_features'] ?? ''),
        ]);

        return back()->with('success', 'Motorcycle profile added.');
    }

    public function updateProductProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,product_id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'dimensions' => ['nullable', 'string', 'max:255'],
            'product_specifications' => ['nullable', 'string', 'max:5000'],
            'required_features' => ['nullable', 'string', 'max:3000'],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);

        $product->update([
            'description' => $validated['description'] ?? null,
            'dimensions' => $validated['dimensions'] ?? null,
            'specifications' => $this->parseSpecifications($validated['product_specifications'] ?? ''),
            'required_features' => $this->parseList($validated['required_features'] ?? ''),
        ]);

        return back()->with('success', 'Product technical profile saved.');
    }

    public function storeFitment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,product_id'],
            'motorcycle_id' => ['required', 'exists:motorcycles,motorcycle_id'],
            'compatibility_status' => ['required', Rule::in(['exact_fit', 'conditional_fit', 'incompatible', 'unverified'])],
            'fitment_notes' => ['nullable', 'string', 'max:3000'],
            'reasons' => ['nullable', 'string', 'max:3000'],
            'conditions' => ['nullable', 'string', 'max:3000'],
            'source_reference' => [
                Rule::requiredIf(fn () => $request->input('compatibility_status') !== 'unverified'),
                'nullable', 'string', 'max:255',
            ],
        ]);

        $verified = $validated['compatibility_status'] !== 'unverified';

        PartCompatibility::updateOrCreate(
            [
                'product_id' => $validated['product_id'],
                'motorcycle_id' => $validated['motorcycle_id'],
            ],
            [
                'compatibility_status' => $validated['compatibility_status'],
                'fitment_notes' => $validated['fitment_notes'] ?? null,
                'reasons' => $this->parseList($validated['reasons'] ?? ''),
                'conditions' => $this->parseList($validated['conditions'] ?? ''),
                'source_reference' => $verified ? $validated['source_reference'] : null,
                'verified_by' => $verified ? auth()->id() : null,
                'verified_at' => $verified ? now() : null,
            ],
        );

        return back()->with('success', 'Fitment record saved.');
    }

    private function parseSpecifications(string $input): array
    {
        $specifications = [];

        foreach (preg_split('/\r\n|\r|\n/', $input) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key !== '' && $value !== '') {
                $specifications[mb_strtolower(str_replace(' ', '_', $key))] = $value;
            }
        }

        return $specifications;
    }

    private function parseList(string $input): array
    {
        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[,\r\n]+/', $input) ?: [],
        ))));
    }
}
