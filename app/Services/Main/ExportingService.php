<?php

namespace App\Services\Main;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportingService
{
    /**
     * Export any Eloquent model as CSV.
     * @param  Request  $request
     * @param  string   $modelClass Fully qualified Model class name
     * @param  string|null  $startDateParam
     * @param  string|null  $endDateParam
     * @param  string|null  $franchiseStoreParam
     * @return StreamedResponse
     */

    /*Export any Eloquent model as CSV.*/
    public function exportCSV(Request $request, string $modelClass, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null): StreamedResponse
    {
        Log::info("{$modelClass} CSV export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // Parse filters
        $startDate       = $startDateParam ?? $request->query('start_date');
        $endDate         = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);

        Log::debug("Export parameters for {$modelClass}", compact('startDate','endDate','franchiseStores'));

        // Build and execute query
        $query = $modelClass::query();
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }
        $data = $query->get();
        $count = $data->count();

        Log::info("Data retrieved for {$modelClass}", compact('count'));

        // Column names: override in controller or extend this service if needed
        $columns = property_exists($modelClass, 'exportColumns')
            ? $modelClass::$exportColumns
            : array_keys($modelClass::first()->getAttributes());

        // Stream callback
        $callback = function() use ($data, $columns) {
            $handle = fopen('php://output','w');
            fputcsv($handle, $columns);
            foreach ($data as $item) {
                $row = array_map(fn($col) => $item->{$col}, $columns);
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        $filename = $this->makeFilename((new \ReflectionClass($modelClass))->getShortName(), $startDate, $endDate, $franchiseStores, 'csv');

        return response()->streamDownload($callback, $filename, ['Content-Type'=>'text/csv']);
    }

    /*Export any Eloquent model as JSON.*/
    public function exportJson(Request $request, string $modelClass, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info("{$modelClass} JSON export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate   = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);

        $query = $modelClass::query();
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }
        $data  = $query->get();
        $count = $data->count();

        return response()->json([
            'success'      => true,
            'record_count' => $count,
            'data'         => $data,
        ]);
    }

    /** Parse franchise_store list */
    protected function parseStores(Request $request, $param): array
    {
        $list = [];
        if (!empty($param)) {
            $list = explode(',', $param);
        } elseif ($q = $request->query('franchise_store')) {
            $list = strpos($q, ',') !== false ? explode(',', $q) : [$q];
        }
        return array_filter(array_map('trim', $list), fn($v) => $v && $v !== 'null' && $v !== 'undefined');
    }

    /** Build filename */
    protected function makeFilename(string $base, $start, $end, array $stores, string $ext): string
    {
        $name = strtolower($base) . '_' . ($start && $end ? "{$start}_to_{$end}" : 'all_dates');
        if (!empty($stores)) {
            $name .= '_stores_' . count($stores);
        }
        return "{$name}.{$ext}";
    }

}
