<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierImportRow;
use App\Models\SupplierPrice;
use App\Services\AiSupplierPriceExtractor;
use App\Services\ProductDuplicateGuard;
use App\Services\SupplierProductMatcher;
use App\Services\SupplierSpreadsheetImporter;
use App\Support\TablePagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;
use InvalidArgumentException;

class SupplierPriceController extends Controller
{
    public function index(Request $request, SupplierProductMatcher $productMatcher): View
    {
        $productMatcher->linkUnmatchedPricesBySku();

        $search = trim((string) $request->query('search'));
        $importSearch = trim((string) $request->query('import_search'));
        $perPage = TablePagination::size($request);
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
        $publishedPriceList = $imports->where('status', 'approved')->whereNull('archived_at')
            ->sortByDesc('approved_at')->first()?->getKey() ?? 0;
        $importHistory = SupplierImport::query()->with('supplier')->latest()->orderByDesc('supplier_import_id')
            ->paginate(TablePagination::size($request, 'history_rows'), ['*'], 'history_page')
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
                ->paginate(TablePagination::size($request, 'import_rows'), ['*'], 'import_page')->withQueryString()->fragment('import-records');
        }

        $summary = [
            'suppliers' => Supplier::query()->where('is_active', true)->count(),
            'prices' => $prices->total(),
            'changes' => (clone $priceSummaryQuery)->reorder()->whereNotNull('previous_price')->whereColumn('unit_price', '!=', 'previous_price')->count(),
            'stale' => (clone $priceSummaryQuery)->reorder()->where('last_updated_at', '<', now()->subDays(30))->count(),
        ];

        return view('admin.suppliers', compact('prices', 'imports', 'publishedPriceList', 'importHistory', 'selectedImport', 'importRows', 'summary', 'catalogProducts', 'categories', 'shelves', 'sort', 'search', 'importSearch', 'perPage'));
    }

    public function upload(Request $request, SupplierSpreadsheetImporter $importer, AiSupplierPriceExtractor $extractor): RedirectResponse
    {
        $request->merge(['default_currency' => Str::upper(trim((string) $request->input('default_currency', 'PHP')))]);
        $mode = $request->input('import_mode', 'spreadsheet');
        $types = $mode === 'ai' ? 'csv,txt,xlsx,pdf,docx,png,jpg,jpeg,webp' : 'csv,txt,xlsx';
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'import_mode' => ['nullable', Rule::in(['spreadsheet', 'ai'])],
            'default_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'price_file' => ['required', 'file', 'mimes:'.$types],
        ]);
        $file = $request->file('price_file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $duplicate = SupplierImport::query()->where('file_hash', $fileHash)->first();
        if ($duplicate) {
            throw ValidationException::withMessages(['price_file' => 'This exact file was already imported on '.$duplicate->created_at->format('M d, Y h:i A').'. Choose a newer price list.']);
        }
        try {
            // Known supplier headings convert locally; unfamiliar layouts use AI automatically.
            if ($mode !== 'ai' && ! $importer->recognizesColumns($file->getRealPath(), $file->getClientOriginalExtension())) {
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
                if ($mode === 'ai') {
                    $importer->stageExtractedRows($import, $rows, $validated['default_currency']);
                } else {
                    $importer->stage($import, $file->getRealPath(), $file->getClientOriginalExtension());
                }

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
            'manufacturer_part_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'shop_received_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'confirm_review' => ['accepted'],
        ]);
        unset($values['confirm_review']);
        DB::transaction(function () use ($supplierImport, $row, $values, $importer) {
            $locked = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImport->supplier_import_id);
            abort_unless($locked->status === 'pending' && ! $locked->archived_at, 403, 'Only pending imports can be corrected.');
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
            abort_unless($supplierImport->status === 'pending' && ! $supplierImport->archived_at, 409, 'This import has already been processed or archived.');
            if ($supplierImport->error_count > 0) {
                throw ValidationException::withMessages(['import' => 'Review and correct the flagged import rows before approval.']);
            }
            foreach ($supplierImport->rows()->lazyById(250, 'supplier_import_row_id') as $row) {
                if (! $row->product_id) {
                    $matchedProduct = $productMatcher->match($row->internal_sku, $row->supplier_sku, $row->product_name);
                    if ($matchedProduct) {
                        $row->update([
                            'product_id' => $matchedProduct->product_id,
                            'internal_sku' => $matchedProduct->sku,
                        ]);
                    }
                }

                $price = SupplierPrice::query()->lockForUpdate()->firstOrNew([
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
                    'manufacturer_part_number' => $row->manufacturer_part_number,
                    'shop_received_quantity' => $row->shop_received_quantity,
                    'stock_applied_at' => null,
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

    public function stockReview(Request $request, SupplierPrice $supplierPrice): View
    {
        $validated = $request->validate(['product_id' => ['sometimes', 'required', 'integer']]);
        $product = Product::query()->where('is_active', true)->findOrFail($validated['product_id'] ?? $supplierPrice->product_id);
        $supplierPrice->load('latestHistory')->setRelation('product', $product);

        return view('admin.supplier-stock-review', [
            'prices' => collect([$supplierPrice]),
            'import' => null,
            'action' => route('admin.suppliers.prices.apply-stock', $supplierPrice),
            'backUrl' => route('admin.suppliers'),
        ]);
    }

    public function bulkStockReview(SupplierImport $supplierImport): View
    {
        abort_unless($supplierImport->status === 'approved' && ! $supplierImport->archived_at, 404);
        $prices = SupplierPrice::query()
            ->with(['latestHistory', 'product'])
            ->whereHas('latestHistory', fn ($query) => $query->where('supplier_import_id', $supplierImport->getKey()))
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->whereNull('stock_applied_at')
            ->orderBy('supplier_price_id')
            ->paginate(100);

        return view('admin.supplier-stock-review', [
            'prices' => $prices,
            'import' => $supplierImport,
            'action' => route('admin.suppliers.imports.apply-stock', $supplierImport),
            'backUrl' => route('admin.suppliers', ['import' => $supplierImport->getKey()]),
        ]);
    }

    public function applyStock(Request $request, SupplierPrice $supplierPrice): RedirectResponse|JsonResponse
    {
        $count = $this->applyReviewedStock($request, $supplierPrice, null);
        $message = $count
            ? 'Product match saved and stock added in Stock Management.'
            : 'Product match saved.';
        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['message' => $message]);
        }

        return redirect()->route('admin.suppliers')->with('success', $message);
    }

    public function applyBulkStock(Request $request, SupplierImport $supplierImport): RedirectResponse|JsonResponse
    {
        abort_unless($supplierImport->status === 'approved' && ! $supplierImport->archived_at, 404);
        $count = $this->applyReviewedStock($request, null, $supplierImport);
        $message = "Stock added for {$count} supplier items.";
        $reviewUrl = route('admin.suppliers.imports.stock-review', $supplierImport);
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'review_url' => $reviewUrl]);
        }

        return redirect()->to($reviewUrl)->with('success', $message);
    }

    private function applyReviewedStock(Request $request, ?SupplierPrice $singlePrice, ?SupplierImport $import): int
    {
        $rows = $request->validate([
            'rows' => ['required', 'array', $singlePrice ? 'size:1' : 'max:100'],
            'rows.*' => ['required', 'array:price_id,product_id,version,current_stock,quantity'],
            'rows.*.price_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.product_id' => ['required', 'integer', 'min:1'],
            'rows.*.version' => ['required', 'integer', 'min:0'],
            'rows.*.current_stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'rows.*.quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ])['rows'];
        $rows = collect($rows)->filter(fn ($row) => $singlePrice || (int) $row['quantity'] > 0)->sortBy('price_id');
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Enter a quantity above zero for at least one item.']);
        }

        return DB::transaction(function () use ($request, $rows, $singlePrice, $import) {
            $prices = SupplierPrice::query()->whereKey($rows->pluck('price_id'))
                ->orderBy('supplier_price_id')->lockForUpdate()->get()->keyBy('supplier_price_id');
            $products = Product::query()->whereKey($rows->pluck('product_id')->unique())
                ->where('is_active', true)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $added = 0;
            $startingStocks = $products->map->current_stock;

            foreach ($rows as $row) {
                $price = $prices->get($row['price_id']);
                $product = $products->get($row['product_id']);
                $quantity = (int) $row['quantity'];
                $history = $price?->latestHistory()->first();
                if (! $price || ! $product || (int) ($history?->getKey() ?? 0) !== (int) $row['version']
                    || ($singlePrice && $price->getKey() !== $singlePrice->getKey())
                    || ($import && ((int) $history?->supplier_import_id !== (int) $import->getKey()
                        || (int) $price->product_id !== (int) $product->getKey()))) {
                    throw ValidationException::withMessages(['rows' => 'A supplier item or its product match changed. Review the latest details and try again.']);
                }

                if ($quantity > 0) {
                    if ($price->stock_applied_at) {
                        throw ValidationException::withMessages(['rows' => "Stock for {$price->supplier_sku} was already added."]);
                    }
                    if ((int) $startingStocks->get($product->getKey()) !== (int) $row['current_stock']) {
                        throw ValidationException::withMessages(['rows' => "The stock balance for {$product->sku} changed. Review the updated balance before confirming."]);
                    }
                    if ($product->current_stock + $quantity > 2147483647) {
                        throw ValidationException::withMessages(['rows' => "The stock total for {$product->sku} exceeds the supported maximum."]);
                    }

                    $product->update(['current_stock' => $product->current_stock + $quantity]);
                    InventoryLedger::create([
                        'product_id' => $product->getKey(),
                        'user_id' => $request->user()->id,
                        'qty_in' => $quantity,
                        'qty_out' => 0,
                        'reason_code' => 'SUPPLIER_IMPORT',
                        'logs' => "Stock added from supplier SKU {$price->supplier_sku} ({$price->source_filename}).",
                    ]);
                    $price->update(['stock_applied_at' => now()]);
                    $added++;
                }

                if ($singlePrice) {
                    $price->update(['product_id' => $product->getKey(), 'auto_match_disabled' => false]);
                    $this->syncImportRowMatches($price, $product);
                }
            }

            return $added;
        }, 3);
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
            'initial_stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
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
            $supplierPrice = SupplierPrice::query()->lockForUpdate()->findOrFail($supplierPrice->getKey());
            if ($supplierPrice->product_id) {
                throw ValidationException::withMessages(['product_id' => 'This supplier item is already matched to a product.']);
            }
            $openingStock = (int) ($validated['initial_stock'] ?? 0);
            $attributes = [
                'sku' => trim($validated['sku']),
                'name' => $supplierPrice->product_name,
                'manufacturer_part_number' => $validated['manufacturer_part_number'],
                'description' => "Created from {$supplierPrice->supplier->name} supplier price {$supplierPrice->supplier_sku}.",
                'category' => $this->nullableCleanString($validated['category'] ?? null),
                'shelf_location' => $this->nullableCleanString($validated['shelf_location'] ?? null),
                'unit_cost' => $supplierPrice->unit_price,
                'unit_price' => $validated['selling_price'],
                'current_stock' => $openingStock,
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
                'qty_in' => $openingStock,
                'qty_out' => 0,
                'reason_code' => 'SUPPLIER_IMPORT',
                'logs' => ($inactiveProduct ? 'Previously deleted product restored' : 'Product created')
                    ." from supplier price {$supplierPrice->supplier_sku}. Supplier availability was not added to store inventory.",
            ]);

            $supplierPrice->update([
                'product_id' => $product->product_id,
                'auto_match_disabled' => false,
                'stock_applied_at' => $openingStock > 0 ? now() : $supplierPrice->stock_applied_at,
            ]);
            $this->syncImportRowMatches($supplierPrice, $product);

            return $product;
        });

        return back()->with('success', $inactiveProduct
            ? "Deleted SKU {$product->sku} was restored with {$product->current_stock} opening units and matched to this supplier price."
            : "{$product->sku} was added with {$product->current_stock} opening units and matched to this supplier price.");
    }

    public function validateBulkSelection(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'supplier_price_ids' => ['required', 'array', 'min:1', 'max:500'],
            'supplier_price_ids.*' => ['required', 'integer', 'min:1'],
        ])['supplier_price_ids'];

        $eligible = SupplierPrice::whereIntegerInRaw('supplier_price_id', $ids)
            ->whereNull('product_id')
            ->get(['supplier_price_id', 'currency'])
            ->filter(fn (SupplierPrice $price) => strtoupper($price->currency) === 'PHP')
            ->pluck('supplier_price_id')->values();

        return response()->json(['ids' => $eligible]);
    }

    public function startBulkReview(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'supplier_price_ids' => ['required', 'array', 'min:1', 'max:500'],
            'supplier_price_ids.*' => ['required', 'integer', 'distinct', 'exists:supplier_prices,supplier_price_id'],
        ])['supplier_price_ids'];

        $prices = SupplierPrice::whereIn('supplier_price_id', $ids)->get()->keyBy('supplier_price_id');
        $entries = [];
        foreach ($ids as $id) {
            $price = $prices->get($id);
            if (! $price) {
                throw ValidationException::withMessages(['supplier_price_ids' => 'A selected supplier price was deleted. Refresh your selection and try again.']);
            }
            if ($price->product_id || strtoupper($price->currency) !== 'PHP') {
                throw ValidationException::withMessages(['supplier_price_ids' => "Supplier item {$price->supplier_sku} is no longer eligible."]);
            }
            $entries[$id] = [
                'manufacturer_part_number' => $price->manufacturer_part_number,
                'initial_stock' => (int) ($price->available_quantity ?? $price->shop_received_quantity ?? 0),
                'reorder_level' => 5,
            ];
        }

        $batch = (string) Str::uuid();
        $request->session()->put("supplier_bulk.{$batch}", [
            'owner' => $request->user()->id, 'entries' => $entries,
            'popup' => $request->boolean('popup'),
        ]);

        return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch]);
    }

    public function bulkReview(Request $request, string $batch): View
    {
        $draft = $this->bulkDraft($request, $batch);
        $entries = $draft['entries'];
        $prices = SupplierPrice::whereIn('supplier_price_id', array_keys($entries))->get()->keyBy('supplier_price_id');
        $ready = [];
        foreach ($entries as $id => $details) {
            $ready[$id] = isset($prices[$id]) && $this->bulkDetailsValidator($details, $prices[$id])->passes();
        }
        $selected = $request->integer('item', array_key_first($entries));
        abort_unless(array_key_exists($selected, $entries), 404);
        $categories = Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        $shelves = Product::whereNotNull('shelf_location')->distinct()->orderBy('shelf_location')->pluck('shelf_location');

        return view('admin.supplier-bulk-review', compact('batch', 'entries', 'prices', 'ready', 'selected', 'categories', 'shelves'));
    }

    public function saveBulkReview(Request $request, string $batch, int $item): RedirectResponse
    {
        $draft = $this->bulkDraft($request, $batch);
        abort_unless(array_key_exists($item, $draft['entries']), 404);
        $price = SupplierPrice::findOrFail($item);
        $details = $request->only(array_keys($this->bulkDetailsRules()));
        // Keep invalid scalar entries so the owner can correct them.
        $draft['entries'][$item] = array_filter($details, fn ($value) => is_scalar($value) || $value === null);
        $request->session()->put("supplier_bulk.{$batch}", $draft);
        $destination = $request->integer('destination');
        if (array_key_exists($destination, $draft['entries'])) {
            return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch, 'item' => $destination]);
        }

        $validation = $this->bulkDetailsValidator($details, $price);
        if ($validation->fails()) {
            return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch, 'item' => $item])
                ->withErrors($validation);
        }

        $prices = SupplierPrice::whereIn('supplier_price_id', array_keys($draft['entries']))->get()->keyBy('supplier_price_id');
        foreach ($draft['entries'] as $id => $entry) {
            if (! isset($prices[$id]) || $this->bulkDetailsValidator($entry, $prices[$id])->fails()) {
                return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch, 'item' => $id]);
            }
        }

        return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch, 'item' => $item]);
    }

    public function bulkCreateProducts(Request $request, ProductDuplicateGuard $duplicates): RedirectResponse
    {
        $batch = $request->validate(['batch' => ['required', 'uuid']])['batch'];
        if ($request->session()->get("supplier_bulk_completed.{$batch}") === $request->user()->id) {
            return redirect()->route('admin.suppliers')->with('success', 'This batch was already created.');
        }
        $draft = $this->bulkDraft($request, $batch);
        $item = array_key_first($draft['entries']);
        try {
            DB::transaction(function () use ($request, $draft, $duplicates, &$item) {
                $prices = SupplierPrice::with('supplier')->whereIn('supplier_price_id', array_keys($draft['entries']))
                    ->orderBy('supplier_price_id')->lockForUpdate()->get()->keyBy('supplier_price_id');
                foreach ($draft['entries'] as $item => $entry) {
                    $price = $prices->get($item);
                    if (! $price) {
                        throw ValidationException::withMessages(['supplier_price' => 'This supplier item was removed. Start a new review.']);
                    }
                    $details = $this->resolveCatalogOptions($this->bulkDetailsValidator($entry, $price)->validate());
                    $sku = trim($price->supplier_sku);
                    $initialStock = (int) $details['initial_stock'];
                    $inactiveProduct = $duplicates->findInactiveBySku($sku);
                    $duplicates->assertUnique([
                        'sku' => $sku,
                        'name' => $price->product_name,
                        'manufacturer_part_number' => $details['manufacturer_part_number'],
                    ], $inactiveProduct, 'bulk_create');

                    $attributes = [
                        'sku' => $sku,
                        'name' => $price->product_name,
                        'manufacturer_part_number' => $details['manufacturer_part_number'],
                        'description' => "Bulk-created from {$price->supplier->name} supplier price {$price->supplier_sku}.",
                        'category' => $this->nullableCleanString($details['category'] ?? null),
                        'shelf_location' => $this->nullableCleanString($details['shelf_location'] ?? null),
                        'unit_cost' => $price->unit_price,
                        'unit_price' => round((float) $price->unit_price * (1 + ((float) $details['markup_percent'] / 100)), 2),
                        'current_stock' => $initialStock,
                        'reorder_level' => $details['reorder_level'],
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

                    if ($initialStock > 0) {
                        InventoryLedger::create([
                            'product_id' => $product->product_id,
                            'user_id' => $request->user()->id,
                            'qty_in' => $initialStock,
                            'qty_out' => 0,
                            'reason_code' => 'SUPPLIER_IMPORT',
                            'logs' => "Opening stock of {$initialStock} units from supplier item {$price->supplier_sku}.",
                        ]);
                    }
                    $price->update([
                        'product_id' => $product->product_id,
                        'auto_match_disabled' => false,
                        'stock_applied_at' => $initialStock > 0 ? now() : $price->stock_applied_at,
                    ]);
                    $this->syncImportRowMatches($price, $product);
                }
            });
        } catch (ValidationException $error) {
            return redirect()->route('admin.suppliers.bulk.review', ['batch' => $batch, 'item' => $item])
                ->withErrors($error->errors());
        }

        $request->session()->forget("supplier_bulk.{$batch}");
        $request->session()->put("supplier_bulk_completed.{$batch}", $request->user()->id);

        return redirect()->route('admin.suppliers')
            ->with('bulk_review_finished', $draft['popup'] ?? false)
            ->with('success', count($draft['entries']).' supplier products created.');
    }

    private function bulkDraft(Request $request, string $batch): array
    {
        abort_unless(Str::isUuid($batch), 404);
        $draft = $request->session()->get("supplier_bulk.{$batch}");
        abort_unless($draft && $draft['owner'] === $request->user()->id, 404);

        return $draft;
    }

    private function bulkDetailsRules(): array
    {
        return [
            'manufacturer_part_number' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'category' => ['nullable', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'initial_stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    private function bulkDetailsValidator(array $details, SupplierPrice $price): Validator
    {
        return validator($details, $this->bulkDetailsRules())->after(function ($validator) use ($price, $details) {
            if ($price->product_id || strtoupper($price->currency) !== 'PHP') {
                $validator->errors()->add('supplier_price', 'This supplier item is already matched or does not have a PHP price.');
            }
            if (mb_strlen(trim($price->supplier_sku)) > 100) {
                $validator->errors()->add('sku', 'The supplier SKU exceeds the product SKU limit of 100 characters.');
            }
            if (is_numeric($details['markup_percent'] ?? null)
                && round((float) $price->unit_price * (1 + (float) $details['markup_percent'] / 100), 2) > 9999999999.99) {
                $validator->errors()->add('markup_percent', 'The selling price exceeds the supported amount.');
            }
        });
    }

    public function reject(SupplierImport $supplierImport): RedirectResponse
    {
        DB::transaction(function () use ($supplierImport) {
            $supplierImport = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImport->supplier_import_id);
            abort_unless($supplierImport->status === 'pending' && ! $supplierImport->archived_at, 409, 'This import has already been processed or archived.');
            $supplierImport->delete();
        });

        return redirect()->route('admin.suppliers')->with('success', 'Import rejected and removed.');
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
                ->distinct()->pluck($column)->first(fn ($existing) => mb_strtolower(trim($existing), 'UTF-8') === mb_strtolower($value, 'UTF-8')) ?? $value);
        }

        return $validated;
    }

    private function nullableCleanString(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }
}
