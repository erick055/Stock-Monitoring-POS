<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierImport;
use App\Models\SupplierPrice;
use App\Services\SupplierSpreadsheetImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        $imports = SupplierImport::query()
            ->with(['supplier', 'rows'])
            ->latest()
            ->limit(10)
            ->get();

        $selectedImport = null;
        if ($request->filled('import')) {
            $selectedImport = SupplierImport::query()
                ->with(['supplier', 'rows'])
                ->findOrFail($request->integer('import'));
        }

        $summary = [
            'suppliers' => Supplier::query()->where('is_active', true)->count(),
            'prices' => $prices->count(),
            'changes' => $prices->filter(fn ($price) => $price->previous_price !== null && $price->unit_price !== $price->previous_price)->count(),
            'stale' => $prices->filter(fn ($price) => $price->last_updated_at->lt(now()->subDays(30)))->count(),
        ];

        return view('admin.suppliers', compact('prices', 'imports', 'selectedImport', 'summary'));
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

    public function approve(Request $request, SupplierImport $supplierImport): RedirectResponse
    {
        abort_unless($supplierImport->status === 'pending', 409, 'This import has already been processed.');
        if ($supplierImport->error_count > 0) {
            return back()->withErrors(['import' => 'Correct the spreadsheet errors and upload a new file before approval.']);
        }

        DB::transaction(function () use ($request, $supplierImport) {
            $supplierImport->load('rows');

            foreach ($supplierImport->rows as $row) {
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

    public function reject(SupplierImport $supplierImport): RedirectResponse
    {
        abort_unless($supplierImport->status === 'pending', 409, 'This import has already been processed.');
        $supplierImport->update(['status' => 'rejected']);

        return redirect()->route('admin.suppliers')->with('success', 'Import rejected. No prices were changed.');
    }

    public function purge(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation_text' => ['required', Rule::in(['DELETE'])],
        ], [
            'confirmation_text.in' => 'Type DELETE exactly to confirm removal of all supplier price data.',
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
}
