<?php

namespace App\Http\Controllers\Data;

use App\Models\DeliveryOrderSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ExportDeliveryOrderSummaryController extends Controller
{
    public function exportCSV(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('DeliveryOrderSummary CSV export requested', [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // 1) Pull parameters from URL segments or query string
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate   = $endDateParam   ?? $request->query('end_date');

        // 2) Handle franchise_store list
        $franchiseStores = [];
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } elseif ($fs = $request->query('franchise_store')) {
            $franchiseStores = array_map('trim', explode(',', $fs));
        }
        $franchiseStores = array_filter($franchiseStores, fn($v) => $v !== '' && $v !== 'null' && $v !== 'undefined');

        Log::debug('DeliveryOrderSummary CSV export parameters', [
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query'        => $request->getQueryString(),
        ]);

        // 3) Build the Eloquent query
        $query = DeliveryOrderSummary::query();
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            $data = $query->get();
            $count = $data->count();

            Log::info('DeliveryOrderSummary retrieved', [
                'count' => $count,
                'range' => $startDate && $endDate ? "{$startDate} to {$endDate}" : 'all',
                'stores'=> $franchiseStores ?: ['all'],
            ]);

            // Columns available on DeliveryOrderSummary
            $columns = (new DeliveryOrderSummary)->getFillable();

            $callback = function() use ($data, $columns) {
                $out = fopen('php://output','w');
                fputcsv($out, $columns);
                foreach ($data as $row) {
                    $csvRow = [];
                    foreach ($columns as $col) {
                        $csvRow[] = $row->{$col};
                    }
                    fputcsv($out, $csvRow);
                }
                fclose($out);
            };

            // Build filename
            $filename = 'delivery_order_summary_' .
                        ($startDate && $endDate ? "{$startDate}_to_{$endDate}" : 'all_dates') .
                        (!empty($franchiseStores) ? '_stores_' . count($franchiseStores) : '') .
                        '.csv';

            Log::info('DeliveryOrderSummary CSV export complete', ['filename' => $filename, 'record_count' => $count]);

            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);

        } catch (\Throwable $e) {
            Log::error('DeliveryOrderSummary CSV export failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function exportJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('DeliveryOrderSummary JSON export requested', [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // Parameter logic
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate   = $endDateParam   ?? $request->query('end_date');

        $franchiseStores = [];
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } elseif ($fs = $request->query('franchise_store')) {
            $franchiseStores = array_map('trim', explode(',', $fs));
        }
        $franchiseStores = array_filter($franchiseStores, fn($v) => $v !== '' && $v !== 'null' && $v !== 'undefined');

        // Build query
        $query = DeliveryOrderSummary::query();
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            $data = $query->get();
            $count = $data->count();

            Log::info('DeliveryOrderSummary JSON export complete', [
                'record_count' => $count,
                'date_range'   => $startDate && $endDate ? "{$startDate} to {$endDate}" : 'all',
                'stores'       => $franchiseStores ?: ['all'],
            ]);

            return response()->json([
                'success'      => true,
                'record_count' => $count,
                'data'         => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('DeliveryOrderSummary JSON export failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function exportToExcel(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('DeliveryOrderSummary Excel export requested', [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // Parameter logic
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate   = $endDateParam   ?? $request->query('end_date');

        $franchiseStores = [];
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } elseif ($fs = $request->query('franchise_store')) {
            $franchiseStores = array_map('trim', explode(',', $fs));
        }
        $franchiseStores = array_filter($franchiseStores, fn($v) => $v !== '' && $v !== 'null' && $v !== 'undefined');

        // Build query
        $query = DeliveryOrderSummary::query();
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            $data = $query->get();
            $count = $data->count();

            Log::info('DeliveryOrderSummary Excel export complete', [
                'record_count' => $count,
                'date_range'   => $startDate && $endDate ? "{$startDate} to {$endDate}" : 'all',
                'stores'       => $franchiseStores ?: ['all'],
            ]);

            // Columns available on DeliveryOrderSummary
            $columns = (new DeliveryOrderSummary)->getFillable();

            $callback = function() use ($data, $columns) {
                $out = fopen('php://output','w');
                fputcsv($out, $columns);
                foreach ($data as $row) {
                    $csvRow = [];
                    foreach ($columns as $col) {
                        $csvRow[] = $row->{$col};
                    }
                    fputcsv($out, $csvRow);
                }
                fclose($out);
            };

            // Build filename
            $filename = 'delivery_order_summary_' .
                        ($startDate && $endDate ? "{$startDate}_to_{$endDate}" : 'all_dates') .
                        (!empty($franchiseStores) ? '_stores_' . count($franchiseStores) : '') .
                        '.csv';

            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);

        } catch (\Throwable $e) {
            Log::error('DeliveryOrderSummary Excel export failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
