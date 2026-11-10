<?php

namespace App\Http\Controllers;

use App\Models\SalesTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalesTargetController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_month' => ['required', 'date_format:Y-m', 'after_or_equal:2000-01', 'before:2101-01'],
            'target_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
        ]);
        SalesTarget::updateOrCreate(['month' => $validated['target_month'].'-01'], [
            'amount' => $validated['target_amount'], 'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.analytics', ['tab' => 'targets'])->with('success', 'Monthly sales target saved.');
    }
}
