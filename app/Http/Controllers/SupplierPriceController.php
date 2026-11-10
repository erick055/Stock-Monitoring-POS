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
        $perPage = \App\Support\TablePagination::size($request);
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

        $priceSummaryQuery = clone $pricesQuery;
        $prices = $pricesQuery
            ->orderBy('supplier_price_id')
            ->paginate($perPage, ['*'], 'prices_page')->withQueryString();

        $catalogProducts = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['product_id', 'sku', 'name', 'unit_cost', 'category', 'shelf_location']);

        $options = fn (string $column) => $catalogProducts->pluck($column)
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->unique(fn ($value) => mb_strtolower(trim((string) $value), 'UTF-8'))
            ->sort(fn ($left, $right) => strcasecmp((string) $left, (string) $right))->values();
        $categories = $options('category');
        $shelves = $options('shelf_location');

        $imports = SupplierImport::query()
            ->with('supplier')
            ->latest()
            ->orderByDesc('supplier_import_id')
            ->get();
        $importHistory = SupplierImport::query()->with('supplier')->latest()->orderByDesc('supplier_import_id')
            ->paginate(\App\Support\TablePagination::size($request, 'history_rows'), ['*'], 'history_page')
            ->withQueryString()->fragment('import-history');

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
                ->paginate(\App\Support\TablePagination::size($request, 'import_rows'), ['*'], 'import_page')->withQueryString()->fragment('import-records');
        }

        $summary = [
            'suppliers' => Supplier::query()->where('is_active', true)->count(),
            'prices' => $prices->total(),
            'changes' => (clone $priceSummaryQuery)->reorder()->whereNotNull('previous_price')->whereColumn('unit_price', '!=', 'previous_price')->count(),
            'stale' => (clone $priceSummaryQuery)->reorder()->where('last_updated_at', '<', now()->subDays(30))->count(),
        ];

        return view('admin.suppliers', compact('prices', 'imports', 'importHistory', 'selectedImport', 'importRows', 'summary', 'catalogProducts', 'categories', 'shelves', 'sort', 'search', 'importSearch', 'perPage'));
    }

    public function upload(Request $request, SupplierSpreadsheetImporter $importer, \App\Services\AiSupplierPriceExtractor $extractor): RedirectResponse
    {
        $request->merge(['default_currency' => Str::upper(trim((string) $request->input('default_currency', 'PHP')))]);
        $mode = $request->input('import_mode', 'spreadsheet');
        $types = $mode === 'ai' ? 'csv,txt,xlsx,pdf,docx,png,jpg,jpeg,webp' : 'csv,txt,xlsx';
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'import_mode' => ['nullable', Rule::in(['spreadsheet', 'ai'])],
            'default_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'price_file' => ['required', 'file', 'max:'.($mode === 'ai' ? '5120' : '10240'), 'mimes:'.$types],
        ]);
        $file = $request->file('price_file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $duplicate = SupplierImport::query()->where('file_hash', $fileHash)->first();
        if ($duplicate) throw ValidationException::withMessages(['price_file' => 'This exact file was already imported on '.$duplicate->created_at->format('M d, Y h:i A').'. Choose a newer price list.']);
        try {
            // Known supplier headings convert locally; unfamiliar layouts use AI automatically.
            if ($mode !== 'ai' && ! $importer->recognizesColumns($file->getRealPath(), $file->getClientOriginalExtension())) {
                if ($file->getSize() > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('This unfamiliar layout needs AI formatting. Split the file into files under 5 MB and 200 items.');
                }
                $mode = 'ai';
            }
            $rows = $mode === 'ai' ? $extractor->extract($file, $validated['default_currency']) : null;
            $import = DB::transaction(function () use ($request, $validated, $file, $fileHash, $mode, $rows, $importer) {
                $supplier = Supplier::query()->updateOrCreate(
                    ['code' => Str::upper($validated['supplier_code'])],
                    ['name' => trim($validated['supplier_name']), 'is_active' => true],
                );
                $import = SupplierImport::create([
                    'supplier_id' => $supplier->supplier_id,
                    'source_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'file_hash' => $fileHash, 'status' => 'pending', 'uploaded_by' => $request->user()->id,
                    'extraction_method' => $mode, 'extraction_model' => $mode === 'ai' ? config('openai.supplier_import_model') : null,
                ]);
                if ($mode === 'ai') $importer->stageExtractedRows($import, $rows, $validated['default_currency']);
                else $importer->stage($import, $file->getRealPath(), $file->getClientOriginalExtension());
                return $import;
            });
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['price_file' => $exception->getMessage()])->withInput($request->except('price_file'));
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['price_file' => 'The document could not be imported. Try a clearer or smaller file, or use a formatted CSV/XLSX. No prices were published.'])->withInput($request->except('price_file'));
        }
        return redirect()->route('admin.suppliers', ['import' => $import->supplier_import_id])
            ->with('success', $mode === 'ai' ? 'AI automatically formatted your file. Review the converted rows and correct any flagged values before approval.' : 'Spreadsheet columns automatically detected and formatted. Review the rows before approval.');
    }

    public function updateImportRow(Request $request, SupplierImport $supplierImport, SupplierImportRow $row, SupplierSpreadsheetImporter $importer): RedirectResponse
    {
        abort_unless((int) $row->supplier_import_id === (int) $supplierImport->supplier_import_id, 404);
        $request->merge([
            'supplier_sku' => trim((string) $request->input('supplier_sku')),
            'product_name' => trim((string) $request->input('product_name')),
            'currency' => Str::upper(trim((string) $request->input('currency'))),
        ]);
        $values = $request->validate([
            'supplier_sku' => ['required', 'string', 'max:255'], 'product_name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'confirm_review' => ['accepted'],
        ]);
        unset($values['confirm_review']);
        DB::transaction(function () use ($supplierImport, $row, $values, $importer) {
            $locked = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImport->supplier_import_id);
            abort_unless($locked->status === 'pending' && !$locked->archived_at, 403, 'Only pending imports can be corrected.');
            $lockedRow = $locked->rows()->lockForUpdate()->findOrFail($row->supplier_import_row_id);
            $importer->reviseRow($locked, $lockedRow, $values);
        });
        return back()->with('success', 'Import row reviewed and saved. Check the remaining rows before publishing.');
    }
    public function approve(Request $request, SupplierImport $supplierImport, SupplierProductMatcher $productMatcher): RedirectResponse
    {
        abort_unless($supplierImport->status === 'pending' && ! $supplierImport->archived_at, 409, 'This import has already been processed or archived.');
        if ($supplierImport->error_count > 0) {
            return back()->withErrors(['import' => 'Review and correct the flagged import rows before approval.']);
        }

        DB::transaction(function () use ($request, $supplierImport, $productMatcher) {
            $supplierImport = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImport->supplier_import_id);
            abort_unless($supplierImport->status === 'pending' && !$supplierImport->archived_at, 409, 'This import has already been processed or archived.');
            if ($supplierImport->error_count > 0) throw ValidationException::withMessages(['import' => 'Review and correct the flagged import rows before approval.']);
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
                    'source_type' => $supplierImport->extraction_method === 'ai' ? 'ai_document' : 'spreadsheet',
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
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'manufacturer_part_number' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'new_category' => ['nullable', 'required_if:category,__new__', 'string', 'max:100', 'regex:/\S/u'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'new_shelf_location' => ['nullable', 'required_if:shelf_location,__new__', 'string', 'max:100', 'regex:/\S/u'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $validated = $this->resolveCatalogOptions($validated);
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
            'initial_stocks' => ['sometimes', 'array', 'max:500'],
            'initial_stocks.*' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'category' => ['nullable', 'string', 'max:100'],
            'new_category' => ['nullable', 'required_if:category,__new__', 'string', 'max:100', 'regex:/\S/u'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'new_shelf_location' => ['nullable', 'required_if:shelf_location,__new__', 'string', 'max:100', 'regex:/\S/u'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $validated = $this->resolveCatalogOptions($validated);

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
            if (round((float) $price->unit_price * (1 + ((float) $validated['markup_percent'] / 100)), 2) > 9999999999.99) {
                throw ValidationException::withMessages(['markup_percent' => 'The markup produces a selling price above the maximum supported amount.']);
            }
        }

        $created = DB::transaction(function () use ($prices, $validated, $request, $duplicates) {
            $count = 0;
            foreach ($prices as $price) {
                $sku = trim($price->supplier_sku);
                $initialStock = (int) ($validated['initial_stocks'][$price->supplier_price_id] ?? 0);
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
                    'current_stock' => $initialStock,
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
                    'qty_in' => $initialStock,
                    'qty_out' => 0,
                    'reason_code' => 'SUPPLIER_IMPORT',
                    'logs' => "Product bulk-created from supplier price {$price->supplier_sku} with {$initialStock} opening units entered by the owner. Supplier availability was not copied to store inventory.",
                ]);
                $price->update(['product_id' => $product->product_id, 'auto_match_disabled' => false]);
                $this->syncImportRowMatches($price, $product);
                $count++;
            }

            return $count;
        });

        return back()->with('success', "{$created} supplier ".Str::plural('item', $created).' added to Products with the entered opening stock.');
    }

    public function reject(SupplierImport $supplierImport): RedirectResponse
    {
        DB::transaction(function () use ($supplierImport) {
            $supplierImport = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImport->supplier_import_id);
            abort_unless($supplierImport->status === 'pending' && !$supplierImport->archived_at, 409, 'This import has already been processed or archived.');
            $supplierImport->update(['status' => 'rejected']);
        });

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

    private function resolveCatalogOptions(array $validated): array
    {
        foreach (['category' => 'new_category', 'shelf_location' => 'new_shelf_location'] as $column => $newField) {
            $value = $this->nullableCleanString(($validated[$column] ?? null) === '__new__'
                ? ($validated[$newField] ?? null) : ($validated[$column] ?? null));
            $validated[$column] = $value === null ? null : (Product::query()->whereNotNull($column)
                ->distinct()->pluck($column)->first(fn ($existing) =>
                    mb_strtolower(trim($existing), 'UTF-8') === mb_strtolower($value, 'UTF-8')) ?? $value);
        }

        return $validated;
    }

    private function nullableCleanString(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }
}
