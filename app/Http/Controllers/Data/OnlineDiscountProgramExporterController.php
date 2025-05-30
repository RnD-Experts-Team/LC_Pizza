<?php

namespace App\Http\Controllers\Data;

use App\Models\OnlineDiscountProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class OnlineDiscountProgramExporterController extends Controller
{
    protected function extractParams(Request $request, $startDateParam, $endDateParam, $franchiseStoreParam)
    {
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');

        $franchiseStores = [];

        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } else {
            $fsParam = $request->query('franchise_store');
            if (!empty($fsParam)) {
                $franchiseStores = strpos($fsParam, ',') !== false
                    ? array_map('trim', explode(',', $fsParam))
                    : [$fsParam];
            }
        }

        $franchiseStores = array_filter($franchiseStores, fn($val) => !empty($val) && $val !== 'null' && $val !== 'undefined');

        return [$startDate, $endDate, $franchiseStores];
    }

    protected function buildQuery($startDate, $endDate, $franchiseStores)
    {
        $query = OnlineDiscountProgram::query();

        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]); // Changed from 'date' to 'business_date'
        }

        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        return $query;
    }

    public function exportCSV(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('OnlineDiscountProgram CSV export requested');

        [$startDate, $endDate, $franchiseStores] = $this->extractParams($request, $startDateParam, $endDateParam, $franchiseStoreParam);
        $query = $this->buildQuery($startDate, $endDate, $franchiseStores);
        $data = $query->get();

        $columns = (new OnlineDiscountProgram)->getFillable();

        $callback = function () use ($data, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            foreach ($data as $item) {
                $row = [];
                foreach ($columns as $col) {
                    $row[] = $item->{$col};
                }
                fputcsv($file, $row);
            }
            fclose($file);
        };

        $filename = 'online_discount_program_' .
            ($startDate && $endDate ? "{$startDate}_to_{$endDate}" : 'all_dates') .
            (!empty($franchiseStores) ? '_stores_' . count($franchiseStores) : '') .
            '.csv';

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('OnlineDiscountProgram JSON export requested');

        [$startDate, $endDate, $franchiseStores] = $this->extractParams($request, $startDateParam, $endDateParam, $franchiseStoreParam);
        $query = $this->buildQuery($startDate, $endDate, $franchiseStores);

        try {
            $data = $query->get();
            return response()->json([
                'success' => true,
                'record_count' => $data->count(),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('OnlineDiscountProgram export JSON error', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function exportToExcel(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('OnlineDiscountProgram Excel export requested');

        [$startDate, $endDate, $franchiseStores] = $this->extractParams($request, $startDateParam, $endDateParam, $franchiseStoreParam);
        $query = $this->buildQuery($startDate, $endDate, $franchiseStores);
        $data = $query->get();
        $columns = (new OnlineDiscountProgram)->getFillable();

        $callback = function () use ($data, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            foreach ($data as $item) {
                $row = [];
                foreach ($columns as $col) {
                    $row[] = $item->{$col};
                }
                fputcsv($file, $row);
            }
            fclose($file);
        };

        $filename = 'online_discount_program_' .
            ($startDate && $endDate ? "{$startDate}_to_{$endDate}" : 'all_dates') .
            (!empty($franchiseStores) ? '_stores_' . count($franchiseStores) : '') .
            '.csv';

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
