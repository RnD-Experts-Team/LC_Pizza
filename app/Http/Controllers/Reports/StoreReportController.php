<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Services\Reports\StoreOverviewService;
use App\Services\Reports\ItemBreakdownService;
use App\Services\Reports\SoldWithPizzaService;
use Illuminate\Http\JsonResponse;

/**
 * StoreReportController
 *
 * Single endpoint to gather:
 *  - Overview totals (StoreOverviewService)
 *  - Item breakdowns (ItemBreakdownService)
 *  - Sold With Pizza metrics (SoldWithPizzaService)
 *
 * Notes:
 *  - No caching, every call computes fresh data.
 *  - Each service only performs lightweight scalar aggregates (sum/count/exists/first).
 *  - Fully Eloquent-based; zero raw SQL.
 *  - Safe for large datasets (100k+ lines) with low memory usage.
 */
class StoreReportController extends Controller
{
    public function __construct(
        private readonly StoreOverviewService  $overview,
        private readonly ItemBreakdownService $items,
        private readonly SoldWithPizzaService $soldWithPizza
    ) {}

    /**
     * Handle GET /api/reports/store
     *
     * Query params:
     *  - franchise_store (string, required)
     *  - from (Y-m-d, required)
     *  - to (Y-m-d, required)
     */
    public function __invoke(StoreReportRequest $request): JsonResponse
    {
        $store = $request->inputStore();
        $from  = $request->inputFrom();
        $to    = $request->inputTo();

        // Build the three report sections
        $data = [
            'overview'        => $this->overview->overview($store, $from, $to),
            'item_breakdown'  => $this->items->breakdown($store, $from, $to),
            'sold_with_pizza' => $this->soldWithPizza->metrics($store, $from, $to),
        ];

        return response()->json([
            'store' => $store,
            'from'  => $from,
            'to'    => $to,
            'data'  => $data,
        ]);
    }
}
