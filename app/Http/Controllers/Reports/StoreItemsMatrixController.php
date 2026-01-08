<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreItemsMatrixRequest;
use App\Services\Reports\StoreItemsMatrixService;
use Illuminate\Http\JsonResponse;

class StoreItemsMatrixController extends Controller
{
    public function __construct(private readonly StoreItemsMatrixService $svc) {}

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
            'to'   => $request->to(),
            'filters' => [
                'stores' => $request->storeFilter(),
                'items'  => $request->itemFilter(),
                'without_bundle' => $request->withoutBundle(),
            ],
            'data' => $data,
        ]);
    }
}
