<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\SellerReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Sales reports: totals for a period, week by week, best sellers and low stock. */
class ReportController extends Controller
{
    public function __invoke(Request $request, SellerReportService $reports): View
    {
        return view('seller.reports', [
            'report' => $reports->report($request->user()->seller, $request->integer('days', 30)),
            'periods' => SellerReportService::PERIODS,
        ]);
    }
}
