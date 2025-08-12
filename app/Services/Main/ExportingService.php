<?php

namespace App\Services\Main;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
    public function exportCSV(Request $request, string $modelClass, $startDateParam = null, $endDateParam = null, $hoursParam = null, $franchiseStoreParam = null): StreamedResponse
    {
        Log::info("{$modelClass} CSV export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // Parse filters
        $startDate       = $startDateParam ?? $request->query('start_date');
        $endDate         = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);
        $hours           = $this->parseHours($request, $hoursParam);

        Log::debug("Export parameters for {$modelClass}", compact('startDate','endDate','franchiseStores'));

        // start and end date
        $query = $modelClass::query();
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        //stores
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }
        //hours
        $tableHasHour = false;
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $tableHasHour = Schema::hasColumn($table, 'hour');
        } catch (\Throwable $e) {
            $tableHasHour = false;
        }

        if (!empty($hours) && $this->modelHasColumn($modelClass, 'hour')) {
            $query->whereIn('hour', $hours);
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
    public function exportJson(Request $request, string $modelClass, $startDateParam = null, $endDateParam = null, $hoursParam = null, $franchiseStoreParam = null)
    {
        Log::info("{$modelClass} JSON export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate   = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);
        $hours           = $this->parseHours($request, $hoursParam);
        //start and end date filter
        $query = $modelClass::query();
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        //store filter
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }
        //hour filter
        $tableHasHour = false;
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $tableHasHour = Schema::hasColumn($table, 'hour');
        } catch (\Throwable $e) {
            $tableHasHour = false;
        }
        if (!empty($hours) && $this->modelHasColumn($modelClass, 'hour')) {
            $query->whereIn('hour', $hours);
        }

        $data  = $query->get();
        $count = $data->count();

        return response()->json([
            'success'      => true,
            'record_count' => $count,
            'data'         => $data,
        ]);
    }


    /****helpers */

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

    protected function parseHours(Request $request, $param): array
    {
        $vals = [];
        if ($param !== null) {
            $vals = explode(',', (string)$param);
        } elseif (($q = $request->query('hours')) !== null) {
            $vals = strpos($q, ',') !== false ? explode(',', $q) : [$q];
        } elseif (($q1 = $request->query('hour')) !== null) {
            $vals = strpos($q1, ',') !== false ? explode(',', $q1) : [$q1];
        }

        $vals = array_map('trim', $vals);
        $vals = array_filter($vals, fn($v) => $v !== '' && is_numeric($v));
        $vals = array_map('intval', $vals);
        // keep within 0..23
        $vals = array_filter($vals, fn($v) => $v >= 0 && $v <= 23);
        return array_values(array_unique($vals));
    }

    /** Safe WHERE IN with bound placeholders */
    protected function safeWhereIn($query, string $column, array $values)
    {
        if (empty($values)) {
            return $query;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        return $query->whereRaw("$column IN ($placeholders)", $values);
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
    protected function modelHasColumn(string $modelClass, string $column): bool
    {
        try {
            $model = new $modelClass;
            return Schema::hasColumn($model->getTable(), $column);
        } catch (\Throwable $e) {
            Log::warning("Column check failed for {$modelClass}.{$column}", ['e' => $e->getMessage()]);
            return false;
        }
    }

}
