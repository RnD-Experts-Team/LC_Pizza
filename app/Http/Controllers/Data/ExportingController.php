<?php

namespace App\Http\Controllers\Data;

use Illuminate\Http\Request;
use App\Services\Main\ExportingService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class ExportingController extends Controller
{
    protected ExportingService $exporting;

    public function __construct(ExportingService $exporting)
    {
        $this->exporting = $exporting;
    }
    // using this array for models that i don't want to export their data
    protected array $restrictedModels = [
        'User',
    ];
    protected function isRestricted(string $model): bool
    {
        return in_array(ucfirst($model), $this->restrictedModels);
    }
    public function exportCSV(Request $request, string $model, $start = null, $end = null, $stores = null)
    {
        if ($this->isRestricted($model)) {
            return response()->json(['error' => 'Exporting this model is not allowed.'], 403);
        }
        // Resolve the full Model class (adjust namespace as needed)
        $modelClass = '\\App\\Models\\' . ucfirst($model);

        return $this->exporting->exportCSV($request, $modelClass, $start, $end, $stores);
    }

    public function exportJson(Request $request, string $model, $start = null, $end = null, $stores = null)
    {
        if ($this->isRestricted($model)) {
            return response()->json(['error' => 'Exporting this model is not allowed.'], 403);
        }
        $modelClass = '\\App\\Models\\' . ucfirst($model);
        return $this->exporting->exportJson($request, $modelClass, $start, $end, $stores);
    }
}
