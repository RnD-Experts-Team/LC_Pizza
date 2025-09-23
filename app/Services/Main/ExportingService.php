<?php

namespace App\Services\Main;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;                                   // [ADDED]
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportingService
{
    // [ADDED] Central knob for chunk size (tune as needed: 2k–20k)
    protected const CHUNK_SIZE = 5000;

    /**
     * Export any Eloquent model as CSV.
     */
    public function exportCSV(
        Request $request,
        string $modelClass,
        $startDateParam = null,
        $endDateParam = null,
        $hoursParam = null,
        $franchiseStoreParam = null
    ): StreamedResponse {
        Log::info("{$modelClass} CSV export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        DB::connection()->disableQueryLog();                          // [ADDED]
        set_time_limit(0);                                            // [ADDED]

        // Parse filters (unchanged)
        $startDate       = $startDateParam ?? $request->query('start_date');
        $endDate         = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);
        $hours           = $this->parseHours($request, $hoursParam);

        Log::debug("Export parameters for {$modelClass}", compact('startDate','endDate','franchiseStores'));

        // Build base query (same filters)
        $query = $modelClass::query();
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        // hour filter (unchanged logic)
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

        // [REMOVED] $data = $query->get();
        // [REMOVED] $count = $data->count();

        // Column list (keep your override)
        if (property_exists($modelClass, 'exportColumns')) {
            $columns = $modelClass::$exportColumns;
        } else {
            $table   = (new $modelClass)->getTable();
            $columns = Schema::getColumnListing($table);              // [CHANGED] no data hydration
        }

        // [ADDED] Only select needed columns
        if (!empty($columns)) {
            $query->select($columns);
        }

        // [ADDED] Determine primary key for chunking
        $pk = (new $modelClass)->getKeyName();
        $hasPk = $this->modelHasColumn($modelClass, $pk);

        // [CHANGED] Stream + chunk
        $callback = function () use ($query, $columns, $pk, $hasPk) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            if ($hasPk) {
                // [ADDED] Chunk by primary key for stable, gapless iteration
                $query->toBase()->orderBy($pk)->chunkById(
                    self::CHUNK_SIZE,
                    function ($rows) use ($out, $columns) {
                        foreach ($rows as $row) { // $row = stdClass
                            $line = [];
                            foreach ($columns as $col) {
                                $line[] = $row->{$col} ?? null;
                            }
                            fputcsv($out, $line);
                        }
                        // DO NOT accumulate rows — write and forget
                    },
                    $pk // column
                );
            } else {
                // [ADDED] Fallback: cursor (when no numeric/incrementing PK)
                foreach ($query->toBase()->cursor() as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $row->{$col} ?? null;
                    }
                    fputcsv($out, $line);
                }
            }

            fclose($out);
            if (function_exists('flush')) { @flush(); }               // [ADDED]
        };

        $filename = $this->makeFilename((new \ReflectionClass($modelClass))->getShortName(), $startDate, $endDate, $franchiseStores, 'csv');

        return response()->streamDownload($callback, $filename, [      // [CHANGED]
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Export any Eloquent model as JSON.
     * Keeps original shape: { success, record_count, data: [...] }
     */
    public function exportJson(
        Request $request,
        string $modelClass,
        $startDateParam = null,
        $endDateParam = null,
        $hoursParam = null,
        $franchiseStoreParam = null
    ) {
        Log::info("{$modelClass} JSON export requested", [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        DB::connection()->disableQueryLog();                          // [ADDED]
        set_time_limit(0);                                            // [ADDED]

        // Parse filters (unchanged)
        $startDate       = $startDateParam ?? $request->query('start_date');
        $endDate         = $endDateParam   ?? $request->query('end_date');
        $franchiseStores = $this->parseStores($request, $franchiseStoreParam);
        $hours           = $this->parseHours($request, $hoursParam);

        // Build base query (same filters)
        $base = $modelClass::query();
        if ($startDate && $endDate) {
            $base->whereBetween('business_date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $base->whereIn('franchise_store', $franchiseStores);
        }
        $tableHasHour = false;
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $tableHasHour = Schema::hasColumn($table, 'hour');
        } catch (\Throwable $e) {
            $tableHasHour = false;
        }
        if (!empty($hours) && $this->modelHasColumn($modelClass, 'hour')) {
            $base->whereIn('hour', $hours);
        }

        // [REMOVED] $data = $base->get();

        // [ADDED] Stable ordering & primary key detection
        $pk = (new $modelClass)->getKeyName();
        $hasPk = $this->modelHasColumn($modelClass, $pk);

        // [ADDED] Count first (cheap)
        $recordCount = (clone $base)->toBase()->count();

        // [CHANGED] Stream the JSON in chunks, preserving original wrapper shape
        $filename = $this->makeFilename((new \ReflectionClass($modelClass))->getShortName(), $startDate, $endDate, $franchiseStores, 'json');

        return response()->streamDownload(function () use ($base, $recordCount, $pk, $hasPk) {
            echo '{"success":true,"record_count":'.$recordCount.',"data":[';

            $first = true;

            if ($hasPk) {
                $base->toBase()->orderBy($pk)->chunkById(
                    self::CHUNK_SIZE,
                    function ($rows) use (&$first) {
                        foreach ($rows as $row) { // stdClass from Query Builder
                            if (!$first) {
                                echo ',';
                            }
                            echo json_encode($row, JSON_UNESCAPED_UNICODE);
                            $first = false;
                        }
                    },
                    $pk
                );
            } else {
                foreach ($base->toBase()->cursor() as $row) {
                    if (!$first) {
                        echo ',';
                    }
                    echo json_encode($row, JSON_UNESCAPED_UNICODE);
                    $first = false;
                }
            }

            echo ']}';
            if (function_exists('flush')) { @flush(); }               // [ADDED]
        }, $filename, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**** helpers (unchanged) ****/

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
