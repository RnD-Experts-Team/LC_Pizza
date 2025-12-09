<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Services\Reports\StoreOverviewService;
use App\Services\Reports\ItemsAndWithPizzaFusedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;

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
        $store = $request->input('franchise_store'); // nullable
        $from  = $request->inputFrom();
        $to    = $request->inputTo();
        $withoutBundle  = $request->boolean('without_bundle'); // <--- NEW

        // Build sections
        $fused = $this->fused->compute($store, $from, $to, $withoutBundle); // <--- pass it

        return response()->json([
            'store' => $store,
            'from'  => $from,
            'to'    => $to,
            'data'  => [
                'overview'        => $this->overview->overview($store, $from, $to),
                'item_breakdown'  => $fused['item_breakdown'],
                'sold_with_pizza' => $fused['sold_with_pizza'],
                'all_items_union' => $fused['all_items_union'],  // IDs that appeared at least once anywhere
            ],
        ]);
    }

    /**
     * GET /api/reports/sold-with-pizza-details
     *
     * Query params:
     * - from: YYYY-MM-DD (required)
     * - to: YYYY-MM-DD (required)
     * - store: franchise_store code OR "all" (optional)
     * - bucket: in_store|lc_pickup|lc_delivery|third_party|all (optional, default in_store)
     */
    public function soldWithPizzaDetails(Request $request)
    {
        $from   = $request->input('from');
        $to     = $request->input('to');
        $store  = $request->input('store'); // null / store-code / "all"
        $bucket = $request->input('bucket', 'in_store');

        // minimal validation (optional but smart)
        if (!$from || !$to) {
            return response()->json([
                'error' => 'from and to are required (YYYY-MM-DD).'
            ], 422);
        }

        $data = $this->fused->soldWithPizzaDetailsStandalone(
            $store,
            $from,
            $to,
            $bucket
        );

        return response()->json($data);
    }
}
