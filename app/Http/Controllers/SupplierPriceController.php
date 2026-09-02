<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierImportRow;
use App\Models\SupplierPrice;
use App\Services\SupplierProductMatcher;
use App\Services\SupplierSpreadsheetImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class SupplierPriceController extends Controller
{
    public function index(Request $request): View
    {
        $prices = SupplierPrice::query()
            ->with(['supplier', 'product'])
            ->latest('last_updated_at')
            ->get();

        $catalogProducts = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['product_id', 'sku', 'name', 'unit_cost']);

        $imports = SupplierImport::query()
            ->with(['supplier', 'rows'])
            ->latest()
            ->limit(10)
            ->get();

        $selectedImport = null;
        if ($request->filled('import')) {
            $selectedImport = SupplierImport::query()
                ->with(['supplier', 'rows.product'])
                ->findOrFail($request->integer('import'));
        }

        $summary = [
            'suppliers' => Supplier::query()->where('is_active', true)->count(),
            'prices' => $prices->count(),
            'changes' => $prices->filter(fn ($price) => $price->previous_price !== null && $price->unit_price !== $price->previous_price)->count(),
            'stale' => $prices->filter(fn ($price) => $price->last_updated_at->lt(now()->subDays(30)))->count(),
        ];

        return view('admin.suppliers', compact('prices', 'imports', 'selectedImport', 'summary', 'catalogProducts'));
    }

    public function upload(Request $request, SupplierSpreadsheetImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'price_file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);

        $supplier = Supplier::query()->updateOrCreate(
            ['code' => Str::upper($validated['supplier_code'])],
            ['name' => trim($validated['supplier_name']), 'is_active' => true],
        );

        $file = $request->file('price_file');
        $import = SupplierImport::create([
            'supplier_id' => $supplier->supplier_id,
            'source_filename' => $file->getClientOriginalName(),
            'status' => 'pending',
            'uploaded_by' => $request->user()->id,
        ]);

        try {
            $importer->stage($import, $file->getRealPath(), $file->getClientOriginalExtension());
        } catch (InvalidArgumentException $exception) {
            $import->delete();

            return back()->withErrors(['price_file' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.suppliers', ['import' => $import->supplier_import_id])
            ->with('success', 'Spreadsheet staged. Review the rows before approval.');
    }

    public function approve(Request $request, SupplierImport $supplierImport, SupplierProductMatcher $productMatcher): RedirectResponse
    {
        abort_unless($supplierImport->status === 'pending', 409, 'This import has already been processed.');
        if ($supplierImport->error_count > 0) {
            return back()->withErrors(['import' => 'Correct the spreadsheet errors and upload a new file before approval.']);
        }

        DB::transaction(function () use ($request, $supplierImport, $productMatcher) {
            $supplierImport->load('rows');

            foreach ($supplierImport->rows as $row) {
                if (! $row->product_id) {
                    $matchedProduct = $productMatcher->match($row->internal_sku, $row->supplier_sku, $row->product_name);
                    if ($matchedProduct) {
                        $row->update([
                            'product_id' => $matchedProduct->product_id,
                            'internal_sku' => $matchedProduct->sku,
                        ]);
                    }
                }

                $price = SupplierPrice::query()->firstOrNew([
                    'supplier_id' => $supplierImport->supplier_id,
                    'supplier_sku' => $row->supplier_sku,
                ]);
                $previousPrice = $price->exists ? $price->unit_price : null;

                $price->fill([
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'currency' => $row->currency,
                    'unit_price' => $row->unit_price,
                    'previous_price' => $previousPrice,
                    'available_quantity' => $row->available_quantity,
                    'minimum_order_quantity' => $row->minimum_order_quantity,
                    'lead_time_days' => $row->lead_time_days,
                    'effective_date' => $row->effective_date,
                    'source_type' => 'spreadsheet',
                    'source_filename' => $supplierImport->source_filename,
                    'last_updated_at' => now(),
                ])->save();

                $price->histories()->create([
                    'unit_price' => $row->unit_price,
                    'available_quantity' => $row->available_quantity,
                    'supplier_import_id' => $supplierImport->supplier_import_id,
                    'recorded_at' => now(),
                ]);
            }

            $supplierImport->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });

        return redirect()->route('admin.suppliers')->with('success', 'Supplier prices published successfully.');
    }

    public function matchProduct(Request $request, SupplierPrice $supplierPrice): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
        ]);

        $product = Product::query()->findOrFail($validated['product_id']);
        $supplierPrice->update(['product_id' => $product->product_id]);
        $this->syncImportRowMatches($supplierPrice, $product);

        return back()->with('success', "{$supplierPrice->product_name} is now matched to {$product->sku} — {$product->name}.");
    }

    public function applyCost(SupplierPrice $supplierPrice): RedirectResponse
    {
        $this->ensurePesoPrice($supplierPrice);

        $product = DB::transaction(function () use ($supplierPrice) {
            $product = Product::query()->lockForUpdate()->find($supplierPrice->product_id);
            if (! $product) {
                throw ValidationException::withMessages([
                    'product_id' => 'Match this supplier item to a product before applying its cost.',
                ]);
            }

            $product->update(['unit_cost' => $supplierPrice->unit_price]);

            return $product;
        });

        return back()->with('success', "Supplier cost applied to {$product->sku}. Selling price and inventory quantity were not changed.");
    }

    public function createProduct(Request $request, SupplierPrice $supplierPrice): RedirectResponse
    {
        $this->ensurePesoPrice($supplierPrice);

        if ($supplierPrice->product_id) {
            throw ValidationException::withMessages([
                'product_id' => 'This supplier item is already matched. Use Apply supplier cost or change its catalog match.',
            ]);
        }

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);

        $product = DB::transaction(function () use ($validated, $supplierPrice, $request) {
            $product = Product::create([
                'sku' => trim($validated['sku']),
                'name' => $supplierPrice->product_name,
                'description' => "Created from {$supplierPrice->supplier->name} supplier price {$supplierPrice->supplier_sku}.",
                'category' => $this->nullableCleanString($validated['category'] ?? null),
                'shelf_location' => $this->nullableCleanString($validated['shelf_location'] ?? null),
                'unit_cost' => $supplierPrice->unit_price,
                'unit_price' => $validated['selling_price'],
                'current_stock' => 0,
                'reorder_level' => $validated['reorder_level'],
                'is_active' => true,
            ]);

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => 0,
                'qty_out' => 0,
                'reason_code' => 'SUPPLIER_IMPORT',
                'logs' => "Product created from supplier price {$supplierPrice->supplier_sku}. Supplier availability was not added to store inventory.",
            ]);

            $supplierPrice->update(['product_id' => $product->product_id]);
            $this->syncImportRowMatches($supplierPrice, $product);

            return $product;
        });

        return back()->with('success', "{$product->sku} was added to Products with zero store stock and matched to this supplier price.");
    }

    public function reject(SupplierImport $supplierImport): RedirectResponse
    {
        abort_unless($supplierImport->status === 'pending', 409, 'This import has already been processed.');
        $supplierImport->update(['status' => 'rejected']);

        return redirect()->route('admin.suppliers')->with('success', 'Import rejected. No prices were changed.');
    }

    public function purge(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ], [
            'password.current_password' => 'The account password is incorrect. No supplier data was deleted.',
        ]);

        $counts = DB::transaction(function () {
            $counts = [
                'histories' => DB::table('supplier_price_histories')->count(),
                'prices' => DB::table('supplier_prices')->count(),
                'rows' => DB::table('supplier_import_rows')->count(),
                'imports' => DB::table('supplier_imports')->count(),
                'suppliers' => DB::table('suppliers')->count(),
            ];

            DB::table('supplier_price_histories')->delete();
            DB::table('supplier_import_rows')->delete();
            DB::table('supplier_prices')->delete();
            DB::table('supplier_imports')->delete();
            DB::table('suppliers')->delete();

            return $counts;
        });

        Log::warning('All supplier price data was deleted by an administrator.', [
            'administrator_id' => $request->user()->id,
            ...$counts,
        ]);

        return redirect()->route('admin.suppliers')->with(
            'success',
            "Supplier price data cleared: {$counts['suppliers']} suppliers, {$counts['prices']} published prices, and {$counts['imports']} imports removed. You can now import a new price list."
        );
    }

    private function syncImportRowMatches(SupplierPrice $supplierPrice, Product $product): void
    {
        SupplierImportRow::query()
            ->where('supplier_sku', $supplierPrice->supplier_sku)
            ->whereHas('import', fn ($query) => $query->where('supplier_id', $supplierPrice->supplier_id))
            ->update([
                'product_id' => $product->product_id,
                'internal_sku' => $product->sku,
            ]);
    }

    private function ensurePesoPrice(SupplierPrice $supplierPrice): void
    {
        if (strtoupper($supplierPrice->currency) !== 'PHP') {
            throw ValidationException::withMessages([
                'currency' => 'Only PHP supplier prices can be applied to product costs. Convert the price to PHP before applying it.',
            ]);
        }
    }

    private function nullableCleanString(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }
}
