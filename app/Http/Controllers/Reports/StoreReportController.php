<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Services\Reports\StoreOverviewService;
use App\Services\Reports\ItemsAndWithPizzaFusedService;
use Illuminate\Http\JsonResponse;

/**
 * StoreReportController
 *
 * Single endpoint to gather:
 *  - Overview totals (StoreOverviewService)
 *  - Item breakdowns + Sold With Pizza (ItemsAndWithPizzaFusedService) in one pass per bucket
 *
 * Notes:
 *  - No caching, every call computes fresh data.
 *  - All queries are Eloquent/Builder only (no raw SQL).
 *  - Memory-safe via chunkById + tight projections.
 *  - Supports "All" (or null) store to aggregate across all stores.
 */
class StoreReportController extends Controller
{
    public function __construct(
        private readonly StoreOverviewService $overview,
        private readonly ItemsAndWithPizzaFusedService $fused
    ) {}

    /**
     * Handle GET /api/reports/store
     *
     * Query params:
     *  - franchise_store (string|null)  e.g. "03795-00016" or "All" or omitted/null
     *  - from (Y-m-d, required)
     *  - to   (Y-m-d, required)
     */
    public function __invoke(StoreReportRequest $request): JsonResponse
    {
        // Allow null/"All" store: we don’t force a value here.
        // If your FormRequest currently requires a string, loosen it (nullable) or read raw input as below.
        $store = $request->input('franchise_store'); // nullable
        $from  = $request->inputFrom();
        $to    = $request->inputTo();

        // Build sections
        $fused = $this->fused->compute($store, $from, $to);

        return response()->json([
            'store' => $store,
            'from'  => $from,
            'to'    => $to,
            'data'  => [
                'overview'        => $this->overview->overview($store, $from, $to),
                'item_breakdown'  => $fused['item_breakdown'],
                'sold_with_pizza' => $fused['sold_with_pizza'],
            ],
        ]);
    }
}
