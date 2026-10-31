<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\ProductImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sellers add many products at once from a spreadsheet. */
class ProductImportController extends Controller
{
    public function __construct(private ProductImportService $imports) {}

    public function create(): View
    {
        return view('seller.products.import', ['columns' => ProductImportService::COLUMNS, 'maxRows' => ProductImportService::MAX_ROWS]);
    }

    public function template(): Response
    {
        return response($this->imports->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="wibuy-products-example.csv"',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [
            'file.mimes' => 'Save your spreadsheet as CSV (File, Save as, CSV) and upload that file.',
        ]);

        $result = $this->imports->import($request->user()->seller, $request->file('file')->getRealPath());

        if ($result['errors'] !== []) {
            return back()->with('import_errors', $result['errors']);
        }

        return redirect()->route('seller.products.index')
            ->with('status', "{$result['imported']} products added as drafts. Add photos, then set each one to live.");
    }
}
