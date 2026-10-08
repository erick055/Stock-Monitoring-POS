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
use App\Services\ProductDuplicateGuard;
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
    public function index(Request $request, SupplierProductMatcher $productMatcher): View
    {
        $productMatcher->linkUnmatchedPricesBySku();

        $search = trim((string) $request->query('search'));
        $importSearch = trim((string) $request->query('import_search'));
        $sort = (string) $request->query('sort', 'updated_desc');
        $sorts = [
            'updated_desc' => ['last_updated_at', 'desc'],
            'updated_asc' => ['last_updated_at', 'asc'],
            'product' => ['product_name', 'asc'],
            'product_desc' => ['product_name', 'desc'],
            'price_high' => ['unit_price', 'desc'],
            'price_low' => ['unit_price', 'asc'],
            'stock_high' => ['available_quantity', 'desc'],
            'stock_low' => ['available_quantity', 'asc'],
        ];
        if ($sort !== 'supplier' && ! array_key_exists($sort, $sorts)) {
            $sort = 'updated_desc';
        }

        $pricesQuery = SupplierPrice::query()
            ->with(['supplier', 'product'])
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('product_name', 'like', "%{$search}%")
                    ->orWhere('supplier_sku', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('product', fn ($product) => $product
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('manufacturer_part_number', 'like', "%{$search}%"));
            }));
        if ($sort === 'supplier') {
            $pricesQuery->orderBy(
                Supplier::query()->select('name')->whereColumn('suppliers.supplier_id', 'supplier_prices.supplier_id')
            );
        } else {
            [$sortColumn, $sortDirection] = $sorts[$sort];
            $pricesQuery->orderBy($sortColumn, $sortDirection);
        }

        $prices = $pricesQuery
            ->orderBy('supplier_price_id')
            ->get();

        $catalogProducts = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['product_id', 'sku', 'name', 'unit_cost']);

        $imports = SupplierImport::query()
            ->with('supplier')
            ->latest()
            ->orderByDesc('supplier_import_id')
            ->get();

        $selectedImport = null;
        $importRows = null;
        if ($request->filled('import')) {
            $selectedImport = SupplierImport::query()
                ->with('supplier')
                ->findOrFail($request->integer('import'));
            $importRows = $selectedImport->rows()->with('product')
                ->when($importSearch !== '', fn ($query) => $query->where(function ($query) use ($importSearch) {
                    $query->where('product_name', 'like', "%{$importSearch}%")
                        ->orWhere('supplier_sku', 'like', "%{$importSearch}%")
                        ->orWhere('internal_sku', 'like', "%{$importSearch}%")
                        ->orWhereHas('product', fn ($product) => $product
                            ->where('name', 'like', "%{$importSearch}%")
                            ->orWhere('sku', 'like', "%{$importSearch}%"));
                }))
                ->orderBy('row_number')->orderBy('supplier_import_row_id')
                ->paginate(25, ['*'], 'import_page')->withQueryString();
        }

        $summary = [
            'suppliers' => Supplier::query()->where('is_active', true)->count(),
            'prices' => $prices->count(),
            'changes' => $prices->filter(fn ($price) => $price->previous_price !== null && $price->unit_price !== $price->previous_price)->count(),
            'stale' => $prices->filter(fn ($price) => $price->last_updated_at->lt(now()->subDays(30)))->count(),
        ];

        return view('admin.suppliers', compact('prices', 'imports', 'selectedImport', 'importRows', 'summary', 'catalogProducts', 'sort', 'search', 'importSearch'));
    }

    public function upload(Request $request, SupplierSpreadsheetImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'price_file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);

        $file = $request->file('price_file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $duplicate = SupplierImport::query()->where('file_hash', $fileHash)->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'price_file' => 'This exact file was already imported on '.$duplicate->created_at->format('M d, Y h:i A').'. Choose a newer price list.',
            ]);
        }

        $supplier = Supplier::query()->updateOrCreate(
            ['code' => Str::upper($validated['supplier_code'])],
            ['name' => trim($validated['supplier_name']), 'is_active' => true],
        );

        $import = SupplierImport::create([
            'supplier_id' => $supplier->supplier_id,
            'source_filename' => $file->getClientOriginalName(),
            'file_hash' => $fileHash,
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
        abort_unless($supplierImport->status === 'pending' && ! $supplierImport->archived_at, 409, 'This import has already been processed or archived.');
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
                $productId = $price->exists && $price->auto_match_disabled ? null : $row->product_id;

                $price->fill([
                    'product_id' => $productId,
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
        $supplierPrice->update([
            'product_id' => $product->product_id,
            'auto_match_disabled' => false,
        ]);
        $this->syncImportRowMatches($supplierPrice, $product);

        return back()->with('success', "{$supplierPrice->product_name} is now matched to {$product->sku} — {$product->name}.");
    }

    public function unmatchProduct(SupplierPrice $supplierPrice): RedirectResponse
    {
        if (! $supplierPrice->product_id) {
            return back()->withErrors(['product_id' => 'This supplier item is already unmatched.']);
        }

        $product = $supplierPrice->product;

        DB::transaction(function () use ($supplierPrice) {
            $supplierPrice->update([
                'product_id' => null,
                'auto_match_disabled' => true,
            ]);
            $this->clearImportRowMatches($supplierPrice);
        });

        return back()->with('success', "{$supplierPrice->product_name} was unmatched from {$product->sku} — {$product->name}. Product prices and inventory were not changed.");
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

    public function createProduct(Request $request, SupplierPrice $supplierPrice, ProductDuplicateGuard $duplicates): RedirectResponse
    {
        $this->ensurePesoPrice($supplierPrice);

        if ($supplierPrice->product_id) {
            throw ValidationException::withMessages([
                'product_id' => 'This supplier item is already matched. Use Apply supplier cost or change its catalog match.',
            ]);
        }

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'manufacturer_part_number' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);

        $validated['sku'] = trim($validated['sku']);
        $validated['manufacturer_part_number'] = $this->nullableCleanString($validated['manufacturer_part_number']);
        $inactiveProduct = $duplicates->findInactiveBySku($validated['sku']);
        $duplicates->assertUnique([
            'sku' => $validated['sku'],
            'name' => $supplierPrice->product_name,
            'manufacturer_part_number' => $validated['manufacturer_part_number'],
        ], $inactiveProduct);

        $product = DB::transaction(function () use ($validated, $supplierPrice, $request, $inactiveProduct) {
            $attributes = [
                'sku' => trim($validated['sku']),
                'name' => $supplierPrice->product_name,
                'manufacturer_part_number' => $validated['manufacturer_part_number'],
                'description' => "Created from {$supplierPrice->supplier->name} supplier price {$supplierPrice->supplier_sku}.",
                'category' => $this->nullableCleanString($validated['category'] ?? null),
                'shelf_location' => $this->nullableCleanString($validated['shelf_location'] ?? null),
                'unit_cost' => $supplierPrice->unit_price,
                'unit_price' => $validated['selling_price'],
                'current_stock' => 0,
                'reorder_level' => $validated['reorder_level'],
                'is_active' => true,
                'dead_stock_archived_at' => null,
                'dead_stock_archived_by' => null,
                'dead_stock_archive_note' => null,
            ];

            if ($inactiveProduct) {
                $product = Product::query()->lockForUpdate()->findOrFail($inactiveProduct->product_id);
                if ($product->is_active || (int) $product->current_stock !== 0) {
                    throw ValidationException::withMessages([
                        'sku' => 'This deleted SKU cannot be restored because its record is active or still has stock.',
                    ]);
                }
                $product->update($attributes);
            } else {
                $product = Product::create($attributes);
            }

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => 0,
                'qty_out' => 0,
                'reason_code' => 'SUPPLIER_IMPORT',
                'logs' => ($inactiveProduct ? 'Previously deleted product restored' : 'Product created')
                    ." from supplier price {$supplierPrice->supplier_sku}. Supplier availability was not added to store inventory.",
            ]);

            $supplierPrice->update([
                'product_id' => $product->product_id,
                'auto_match_disabled' => false,
            ]);
            $this->syncImportRowMatches($supplierPrice, $product);

            return $product;
        });

        return back()->with('success', $inactiveProduct
            ? "Deleted SKU {$product->sku} was restored in Products with zero store stock and matched to this supplier price."
            : "{$product->sku} was added to Products with zero store stock and matched to this supplier price.");
    }

    public function bulkCreateProducts(Request $request, ProductDuplicateGuard $duplicates): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_price_ids' => ['required', 'array', 'min:1', 'max:500'],
            'supplier_price_ids.*' => ['integer', 'distinct', 'exists:supplier_prices,supplier_price_id'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'category' => ['nullable', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);

        $prices = SupplierPrice::query()
            ->with('supplier')
            ->whereIn('supplier_price_id', $validated['supplier_price_ids'])
            ->whereNull('product_id')
            ->orderBy('supplier_price_id')
            ->get();

        if ($prices->count() !== count($validated['supplier_price_ids'])) {
            throw ValidationException::withMessages([
                'supplier_price_ids' => 'One or more selected supplier items are already matched or no longer available.',
            ]);
        }

        foreach ($prices as $price) {
            $this->ensurePesoPrice($price);
        }

        $created = DB::transaction(function () use ($prices, $validated, $request, $duplicates) {
            $count = 0;
            foreach ($prices as $price) {
                $sku = trim($price->supplier_sku);
                $inactiveProduct = $duplicates->findInactiveBySku($sku);
                $duplicates->assertUnique([
                    'sku' => $sku,
                    'name' => $price->product_name,
                    'manufacturer_part_number' => $sku,
                ], $inactiveProduct, 'bulk_create');

                $attributes = [
                    'sku' => $sku,
                    'name' => $price->product_name,
                    'manufacturer_part_number' => $sku,
                    'description' => "Bulk-created from {$price->supplier->name} supplier price {$price->supplier_sku}.",
                    'category' => $this->nullableCleanString($validated['category'] ?? null),
                    'shelf_location' => $this->nullableCleanString($validated['shelf_location'] ?? null),
                    'unit_cost' => $price->unit_price,
                    'unit_price' => round((float) $price->unit_price * (1 + ((float) $validated['markup_percent'] / 100)), 2),
                    'current_stock' => 0,
                    'reorder_level' => $validated['reorder_level'],
                    'is_active' => true,
                    'dead_stock_archived_at' => null,
                    'dead_stock_archived_by' => null,
                    'dead_stock_archive_note' => null,
                ];

                if ($inactiveProduct) {
                    $product = Product::query()->lockForUpdate()->findOrFail($inactiveProduct->product_id);
                    if ($product->is_active || (int) $product->current_stock !== 0) {
                        throw ValidationException::withMessages([
                            'supplier_price_ids' => "Deleted SKU {$sku} cannot be restored because it is active or still has stock.",
                        ])->errorBag('bulk_create');
                    }
                    $product->update($attributes);
                } else {
                    $product = Product::create($attributes);
                }

                InventoryLedger::create([
                    'product_id' => $product->product_id,
                    'user_id' => $request->user()->id,
                    'qty_in' => 0,
                    'qty_out' => 0,
                    'reason_code' => 'SUPPLIER_IMPORT',
                    'logs' => "Product bulk-created from supplier price {$price->supplier_sku}. Supplier availability was not added to store inventory.",
                ]);
                $price->update(['product_id' => $product->product_id, 'auto_match_disabled' => false]);
                $this->syncImportRowMatches($price, $product);
                $count++;
            }

            return $count;
        });

        return back()->with('success', "{$created} supplier ".Str::plural('item', $created).' added to Products with zero store stock.');
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

            DB::table('supplier_imports')->whereNull('archived_at')->update([
                'archived_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('supplier_prices')->delete();
            DB::table('suppliers')->update(['is_active' => false, 'updated_at' => now()]);

            return $counts;
        });

        Log::warning('Published supplier prices were cleared and their imports archived by an administrator.', [
            'administrator_id' => $request->user()->id,
            ...$counts,
        ]);

        return redirect()->route('admin.suppliers')->with(
            'success',
            "{$counts['prices']} published supplier prices cleared. {$counts['imports']} imports and {$counts['rows']} original rows remain available in the archive."
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

    private function clearImportRowMatches(SupplierPrice $supplierPrice): void
    {
        SupplierImportRow::query()
            ->where('supplier_sku', $supplierPrice->supplier_sku)
            ->whereHas('import', fn ($query) => $query->where('supplier_id', $supplierPrice->supplier_id))
            ->update([
                'product_id' => null,
                'internal_sku' => null,
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
