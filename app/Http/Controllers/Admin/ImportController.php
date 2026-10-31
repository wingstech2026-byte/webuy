<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\ImportOrder;
use App\Models\ImportProduct;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WiBuy Global: import products with landed prices, and moving import orders along the tracking steps. */
class ImportController extends Controller
{
    public function index()
    {
        return view('admin.imports.index', [
            'enabled' => ImportService::enabled(),
            'products' => ImportProduct::latest('id')->get(),
            'orders' => ImportOrder::with('user', 'product')->latest('id')->paginate(30),
            'steps' => ImportService::TRACKING,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'origin' => ['required', 'string', 'max:60'],
            'unit' => ['required', 'string', 'max:40'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'sea_price' => ['nullable', 'required_without:air_price', 'numeric', 'min:0.01'],
            'sea_days' => ['nullable', 'required_with:sea_price', 'integer', 'min:1', 'max:365'],
            'air_price' => ['nullable', 'numeric', 'min:0.01'],
            'air_days' => ['nullable', 'required_with:air_price', 'integer', 'min:1', 'max:365'],
            'deposit_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        ImportProduct::create($data + ['is_active' => true]);

        return back()->with('status', 'Import product added.');
    }

    public function toggle(ImportProduct $importProduct): RedirectResponse
    {
        $importProduct->update(['is_active' => ! $importProduct->is_active]);

        return back()->with('status', $importProduct->is_active ? 'Shown to buyers.' : 'Hidden from buyers.');
    }

    public function advance(Request $request, ImportOrder $importOrder, ImportService $imports): RedirectResponse
    {
        $request->validate(['status' => ['required', Rule::in(ImportService::TRACKING)]]);

        try {
            $imports->advance($importOrder, $request->input('status'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', "{$importOrder->number}: {$importOrder->status_label}. The buyer got an SMS.");
    }
}
