<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Services\LowStockAlertService;
use App\Services\InventoryLiveService;
use App\Services\ProductDuplicateGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockManagementController extends Controller
{
    public function index(Request $request, InventoryLiveService $liveInventory): View
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'name');

        $sorts = [
            'name' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'newest' => ['created_at', 'desc'],
            'stock_high' => ['current_stock', 'desc'],
            'stock_low' => ['current_stock', 'asc'],
            'cost_high' => ['unit_cost', 'desc'],
            'cost_low' => ['unit_cost', 'asc'],
        ];
        if (! array_key_exists($sort, $sorts)) {
            $sort = 'name';
        }
        [$sortColumn, $sortDirection] = $sorts[$sort];

        $products = Product::query()
            ->with('activePromotion')
            ->where('is_active', true)
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('shelf_location', 'like', "%{$search}%");
            }))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('product_id')
            ->get()
            ->when($status !== 'all', fn ($items) => $items->filter(fn ($product) => $product->stock_status === $status)->values());

        $allProducts = Product::query()->where('is_active', true)->orderBy('name')->get();
        $categories = $allProducts
            ->pluck('category')
            ->filter(fn ($category) => trim((string) $category) !== '')
            ->unique(fn ($category) => mb_strtolower(trim((string) $category), 'UTF-8'))
            ->sort(fn ($left, $right) => strcasecmp((string) $left, (string) $right))
            ->values();
        $shelves = $allProducts
            ->pluck('shelf_location')
            ->filter(fn ($shelf) => trim((string) $shelf) !== '')
            ->unique(fn ($shelf) => mb_strtolower(trim((string) $shelf), 'UTF-8'))
            ->sort(fn ($left, $right) => strcasecmp((string) $left, (string) $right))
            ->values();
        $ledgers = InventoryLedger::query()
            ->with(['product', 'user'])
            ->when($search, fn ($query) => $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->latest('ledger_id')
            ->limit(50)
            ->get();

        $summary = [
            'total_sku' => $allProducts->count(),
            'total_units' => $allProducts->sum('current_stock'),
            'critical_low' => $allProducts->filter(fn ($product) => $product->stock_status === 'critical')->count(),
            'stock_value' => $allProducts->sum(fn ($product) => $product->current_stock * (float) $product->unit_cost),
        ];
        $inventoryVersion = $liveInventory->version();

        return view('admin.stock-management', compact('products', 'allProducts', 'categories', 'shelves', 'ledgers', 'summary', 'search', 'status', 'sort', 'inventoryVersion'));
    }

    public function storeProduct(Request $request, LowStockAlertService $alerts, ProductDuplicateGuard $duplicates): RedirectResponse
    {
        $request->merge([
            'sku' => trim((string) $request->input('sku')),
            'name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name'))),
        ]);

        $validated = $request->validateWithBag('addProduct', [
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'new_category' => ['nullable', 'required_if:category,__new__', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'new_shelf_location' => ['nullable', 'required_if:shelf_location,__new__', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'manufacturer_part_number' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'qty_in' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'max:50'],
            'logs' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['category'] = $this->normalizedCategory(
            ($validated['category'] ?? null) === '__new__'
                ? ($validated['new_category'] ?? null)
                : ($validated['category'] ?? null),
        );
        unset($validated['new_category']);
        $validated['shelf_location'] = $this->normalizedShelf(
            ($validated['shelf_location'] ?? null) === '__new__'
                ? ($validated['new_shelf_location'] ?? null)
                : ($validated['shelf_location'] ?? null),
        );
        unset($validated['new_shelf_location']);
        $validated['manufacturer'] = $this->nullableCleanString($validated['manufacturer'] ?? null);
        $validated['manufacturer_part_number'] = $this->nullableCleanString($validated['manufacturer_part_number'] ?? null);
        $inactiveProduct = $duplicates->findInactiveBySku($validated['sku']);
        $duplicates->assertUnique($validated, $inactiveProduct, 'addProduct');

        $product = DB::transaction(function () use ($validated, $request, $inactiveProduct) {
            $attributes = [
                ...collect($validated)->only([
                    'sku', 'name', 'manufacturer', 'manufacturer_part_number', 'category', 'shelf_location',
                    'description', 'unit_cost', 'unit_price', 'reorder_level',
                ])->all(),
                'current_stock' => $validated['qty_in'],
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
                    ])->errorBag('addProduct');
                }
                $product->update($attributes);
            } else {
                $product = Product::create($attributes);
            }

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => $validated['qty_in'],
                'qty_out' => 0,
                'reason_code' => $validated['reason_code'],
                'logs' => ($validated['logs'] ?? null) ?: ($inactiveProduct
                    ? 'Previously deleted product restored to the active catalog with new opening inventory.'
                    : 'Product added to inventory.'),
            ]);

            return $product;
        });

        $alerts->checkProduct($product);

        return back()->with('success', $inactiveProduct
            ? 'The previously deleted SKU was restored with the new product information and opening inventory.'
            : 'Product and opening inventory were added successfully.');
    }

    public function updateProduct(Request $request, Product $product, LowStockAlertService $alerts, ProductDuplicateGuard $duplicates): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $request->merge([
            'sku' => trim((string) $request->input('sku')),
            'name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name'))),
        ]);

        $validated = $request->validateWithBag('editProduct', [
            'edit_product_id' => ['required', 'integer', Rule::in([$product->product_id])],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->product_id, 'product_id')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'new_category' => ['nullable', 'required_if:category,__new__', 'string', 'max:100'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'new_shelf_location' => ['nullable', 'required_if:shelf_location,__new__', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'manufacturer_part_number' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);

        $fields = [
            'sku', 'name', 'manufacturer', 'manufacturer_part_number', 'category',
            'shelf_location', 'description', 'unit_cost', 'unit_price', 'reorder_level',
        ];
        $updates = collect($validated)->only($fields)->all();
        foreach (['sku', 'name', 'manufacturer', 'manufacturer_part_number', 'description'] as $field) {
            $updates[$field] = $this->nullableCleanString($updates[$field] ?? null);
        }
        $updates['sku'] = $updates['sku'] ?? '';
        $updates['name'] = $updates['name'] ?? '';
        $updates['category'] = $this->normalizedCategory(
            ($validated['category'] ?? null) === '__new__'
                ? ($validated['new_category'] ?? null)
                : ($validated['category'] ?? null),
            $product,
        );
        $updates['shelf_location'] = $this->normalizedShelf(
            ($validated['shelf_location'] ?? null) === '__new__'
                ? ($validated['new_shelf_location'] ?? null)
                : ($validated['shelf_location'] ?? null),
            $product,
        );
        $duplicates->assertUnique($updates, $product, 'editProduct');

        $labels = [
            'sku' => 'SKU', 'name' => 'name', 'manufacturer' => 'manufacturer',
            'manufacturer_part_number' => 'manufacturer part number', 'category' => 'category',
            'shelf_location' => 'shelf location', 'description' => 'description',
            'unit_cost' => 'unit cost', 'unit_price' => 'selling price', 'reorder_level' => 'reorder level',
        ];

        $changed = DB::transaction(function () use ($product, $updates, $fields, $labels, $request) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->product_id);
            $before = $product->only($fields);
            $product->update($updates);

            $changes = collect($fields)->filter(fn (string $field) => (string) ($before[$field] ?? '') !== (string) ($product->{$field} ?? ''));
            if ($changes->isNotEmpty()) {
                $details = $changes->map(fn (string $field) => $labels[$field].': '
                    .$this->auditValue($before[$field] ?? null).' → '.$this->auditValue($product->{$field}))->join(' | ');

                InventoryLedger::create([
                    'product_id' => $product->product_id,
                    'user_id' => $request->user()->id,
                    'qty_in' => 0,
                    'qty_out' => 0,
                    'reason_code' => 'PRODUCT_UPDATED',
                    'logs' => 'Product information updated. '.$details,
                ]);

                if ((string) ($before['sku'] ?? '') !== (string) $product->sku) {
                    DB::table('supplier_import_rows')->where('product_id', $product->product_id)
                        ->update(['internal_sku' => $product->sku, 'updated_at' => now()]);
                }
            }

            return [$product->fresh(), $changes->count()];
        });

        [$updatedProduct, $changeCount] = $changed;
        $alerts->checkProduct($updatedProduct);

        return back()->with('success', $changeCount > 0
            ? "{$updatedProduct->name} was updated successfully. {$changeCount} change(s) were documented."
            : "No changes were needed for {$updatedProduct->name}.");
    }

    public function updateShelfLocation(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'shelf_location' => ['nullable', 'string', 'max:100'],
        ]);

        $location = preg_replace('/\s+/u', ' ', trim((string) ($validated['shelf_location'] ?? '')));
        $product->update(['shelf_location' => $location !== '' ? $location : null]);

        return back()->with('success', $location !== ''
            ? "Shelf location for {$product->name} updated to {$location}."
            : "Shelf location for {$product->name} was cleared.");
    }

    private function normalizedCategory(?string $value, ?Product $except = null): ?string
    {
        $category = preg_replace('/\s+/u', ' ', trim((string) $value));
        if ($category === '') {
            return null;
        }

        return Product::query()
            ->whereNotNull('category')
            ->when($except, fn ($query) => $query->where('product_id', '!=', $except->product_id))
            ->distinct()
            ->get(['category'])
            ->first(fn (Product $product) => mb_strtolower(trim($product->category), 'UTF-8') === mb_strtolower($category, 'UTF-8'))
            ?->category ?? $category;
    }

    private function nullableCleanString(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }

    private function normalizedShelf(?string $value, ?Product $except = null): ?string
    {
        $shelf = $this->nullableCleanString($value);
        if ($shelf === null) {
            return null;
        }

        return Product::query()
            ->whereNotNull('shelf_location')
            ->when($except, fn ($query) => $query->where('product_id', '!=', $except->product_id))
            ->distinct()
            ->get(['shelf_location'])
            ->first(fn (Product $product) => mb_strtolower(trim($product->shelf_location), 'UTF-8') === mb_strtolower($shelf, 'UTF-8'))
            ?->shelf_location ?? $shelf;
    }

    private function auditValue(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : 'empty';
    }

    public function destroyProduct(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'current_password'],
            'deletion_reason' => ['required', 'string', 'max:255'],
        ], [
            'password.current_password' => 'The account password is incorrect. The product was not deleted.',
        ]);

        DB::transaction(function () use ($product, $request, $validated) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->product_id);

            if (! $product->is_active) {
                throw ValidationException::withMessages(['product' => 'This product has already been removed from the active catalog.']);
            }

            if ((int) $product->current_stock !== 0) {
                throw ValidationException::withMessages([
                    'product' => "{$product->name} still has {$product->current_stock} unit(s). Record a stock-out or adjustment to zero before deleting it.",
                ]);
            }

            ProductPromotion::query()
                ->where('status', 'active')
                ->where(function ($query) use ($product) {
                    $query->where('product_id', $product->product_id)
                        ->orWhere('bundle_product_id', $product->product_id);
                })
                ->update(['status' => 'ended', 'ended_at' => now()]);

            $product->supplierPrices()->update(['product_id' => null]);
            DB::table('supplier_import_rows')->where('product_id', $product->product_id)->update([
                'product_id' => null,
                'internal_sku' => null,
            ]);

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => 0,
                'qty_out' => 0,
                'reason_code' => 'PRODUCT_REMOVED',
                'logs' => 'Removed from active catalog. Reason: '.trim($validated['deletion_reason']),
            ]);

            $product->update(['is_active' => false]);
        });

        return back()->with('success', "{$product->name} was removed from the active product catalog. Its sales and inventory history were preserved.");
    }

    public function storeMovement(Request $request, LowStockAlertService $alerts): RedirectResponse
    {
        $reasonCodes = [
            'in' => ['PURCHASE_RECEIPT', 'RETURN_TO_STOCK', 'RECOVERED_STOCK'],
            'out' => ['SALE', 'DAMAGED', 'SUPPLIER_RETURN', 'INTERNAL_USE'],
            'adjustment' => ['PHYSICAL_COUNT', 'DATA_CORRECTION', 'SHRINKAGE'],
        ];

        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'movement_type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'reason_code' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'logs' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! in_array($validated['reason_code'], $reasonCodes[$validated['movement_type']], true)) {
            throw ValidationException::withMessages([
                'reason_code' => 'Select a reason that matches the chosen stock movement type.',
            ]);
        }

        $product = DB::transaction(function () use ($validated, $request) {
            $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);
            $quantity = (int) $validated['quantity'];

            if ($validated['movement_type'] === 'in' && $quantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock-in quantity must be positive.']);
            }

            if ($validated['movement_type'] === 'out' && $quantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock-out quantity must be positive.']);
            }

            $change = match ($validated['movement_type']) {
                'in' => $quantity,
                'out' => -$quantity,
                default => $quantity,
            };

            if ($product->current_stock + $change < 0) {
                throw ValidationException::withMessages(['quantity' => 'This movement would make the stock negative.']);
            }

            $product->update(['current_stock' => $product->current_stock + $change]);

            $details = collect([
                ! empty($validated['reference']) ? 'Reference: '.trim($validated['reference']) : null,
                ! empty($validated['counterparty']) ? 'Source / destination: '.trim($validated['counterparty']) : null,
                ! empty($validated['logs']) ? 'Notes: '.trim($validated['logs']) : null,
            ])->filter()->join(' | ');

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => max($change, 0),
                'qty_out' => max(-$change, 0),
                'reason_code' => $validated['reason_code'],
                'logs' => $details ?: null,
            ]);

            return $product->fresh();
        });

        $alerts->checkProduct($product);

        $label = match ($validated['movement_type']) {
            'in' => 'Stock in',
            'out' => 'Stock out',
            default => 'Stock adjustment',
        };

        return back()->with('success', "{$label} recorded successfully. New balance: {$product->current_stock} unit(s).");
    }
}
