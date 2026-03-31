<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreItemsMatrixRequest;
use App\Services\Reports\StoreItemsMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreItemsMatrixController extends Controller
{
    public function __construct(private readonly StoreItemsMatrixService $svc)
    {
    }

    public function __invoke(StoreItemsMatrixRequest $request): JsonResponse
    {
        $data = $this->svc->compute(
            from: $request->from(),
            to: $request->to(),
            stores: $request->storeFilter(), // null means "all stores"
            items: $request->itemFilter(),   // null means "all items"
            withoutBundle: $request->withoutBundle(),
        );

        return response()->json([
            'from' => $request->from(),
            'to' => $request->to(),
            'filters' => [
                'stores' => $request->storeFilter(),
                'items' => $request->itemFilter(),
                'without_bundle' => $request->withoutBundle(),
            ],
            'data' => $data,
        ]);
    }

    public function itemSummary(Request $request)
    {
        // Validate inputs
        $validated = $request->validate([
            'store_id' => 'required|string',
            'item_id' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        // Normalize inputs explicitly
        $storeId = (string) $validated['store_id'];
        $itemId = (string) $validated['item_id'];
        $start = (string) $validated['start_date'];
        $end = (string) $validated['end_date'];

        try {
            $data = $this->svc->getItemSummary(
                $storeId,
                $itemId,
                $start,
                $end
            );

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Throwable $e) {

            // Log full error for debugging (important)
            \Log::error('Item Summary Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch item summary',
            ], 500);
        }
    }
}
