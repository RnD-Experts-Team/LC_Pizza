<?php

namespace App\Http\Controllers\Data;

use App\Models\BreadBoostModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ExportBreadBoostController extends Controller
{
    public function exportCSV(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Bread Boost Data CSV export requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');

        // Handle franchise store as a comma-separated list
        $franchiseStores = [];

        // First check if it was passed as a route parameter
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } else {
            // Try franchise_store parameter from query
            $franchiseStoreString = $request->query('franchise_store');
            if (!empty($franchiseStoreString)) {
                // Check if it's a comma-separated string
                if (strpos($franchiseStoreString, ',') !== false) {
                    $franchiseStores = array_map('trim', explode(',', $franchiseStoreString));
                } else {
                    $franchiseStores = [$franchiseStoreString];
                }
            }
        }

        // Filter out empty values
        $franchiseStores = array_filter($franchiseStores, function($value) {
            return !empty($value) && $value !== 'null' && $value !== 'undefined';
        });

        Log::debug('Bread Boost Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = BreadBoostModel::query();

        // Filter by date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Bread Boost data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_stores' => !empty($franchiseStores) ? implode(', ', $franchiseStores) : 'all stores'
            ]);

            // Define the columns to export based on BreadBoostModel
            $columns = [
                'id',
                'date',
                'franchise_store',
                'classic_order',
                'classic_with_bread',
                'other_pizza_order',
                'other_pizza_with_bread',
                'created_at',
                'updated_at'
            ];

            // Define a callback that writes CSV rows directly to the output stream
            $callback = function() use ($data, $columns) {
                $file = fopen('php://output', 'w');

                // Write the header row
                fputcsv($file, $columns);

                // Write each record as a CSV row
                foreach ($data as $item) {
                    $row = [];
                    foreach ($columns as $col) {
                        $row[] = $item->{$col};
                    }
                    fputcsv($file, $row);
                }
                fclose($file);
            };

            // Generate a filename with filter information
            $filename = 'bread_boost_data_';
            if ($startDate && $endDate) {
                $filename .= $startDate . '_to_' . $endDate;
            } else {
                $filename .= 'all_dates';
            }
            if (!empty($franchiseStores)) {
                $filename .= '_stores_' . count($franchiseStores);
            }
            $filename .= '.csv';

            Log::info('Bread Boost Data CSV export completed', [
                'filename' => $filename,
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Bread Boost data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Bread Boost data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Bread Boost Data JSON data requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');

        // Handle franchise store as a comma-separated list
        $franchiseStores = [];

        // First check if it was passed as a route parameter
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } else {
            // Try franchise_store parameter from query
            $franchiseStoreString = $request->query('franchise_store');
            if (!empty($franchiseStoreString)) {
                // Check if it's a comma-separated string
                if (strpos($franchiseStoreString, ',') !== false) {
                    $franchiseStores = array_map('trim', explode(',', $franchiseStoreString));
                } else {
                    $franchiseStores = [$franchiseStoreString];
                }
            }
        }

        // Filter out empty values
        $franchiseStores = array_filter($franchiseStores, function($value) {
            return !empty($value) && $value !== 'null' && $value !== 'undefined';
        });

        Log::debug('JSON request parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = BreadBoostModel::query();

        // Filter by date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Bread Boost Data JSON data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_stores' => !empty($franchiseStores) ? implode(', ', $franchiseStores) : 'all stores'
            ]);

            return response()->json([
                'success' => true,
                'record_count' => $recordCount,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving Bread Boost Data JSON data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Bread Boost data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportExcel(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Bread Boost export requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');

        // Handle franchise store as a comma-separated list
        $franchiseStores = [];

        // First check if it was passed as a route parameter
        if (!empty($franchiseStoreParam)) {
            $franchiseStores = array_map('trim', explode(',', $franchiseStoreParam));
        } else {
            // Try franchise_store parameter from query
            $franchiseStoreString = $request->query('franchise_store');
            if (!empty($franchiseStoreString)) {
                // Check if it's a comma-separated string
                if (strpos($franchiseStoreString, ',') !== false) {
                    $franchiseStores = array_map('trim', explode(',', $franchiseStoreString));
                } else {
                    $franchiseStores = [$franchiseStoreString];
                }
            }
        }

        // Filter out empty values
        $franchiseStores = array_filter($franchiseStores, function($value) {
            return !empty($value) && $value !== 'null' && $value !== 'undefined';
        });

        Log::debug('Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = BreadBoostModel::query();

        // Filter by date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Bread Boost data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_stores' => !empty($franchiseStores) ? implode(', ', $franchiseStores) : 'all stores'
            ]);

            // Define the columns to export based on BreadBoostModel
            $columns = [
                'id',
                'date',
                'franchise_store',
                'classic_order',
                'classic_with_bread',
                'other_pizza_order',
                'other_pizza_with_bread',
                'created_at',
                'updated_at'
            ];

            // Define a callback that writes CSV rows directly to the output stream
            $callback = function() use ($data, $columns) {
                $file = fopen('php://output', 'w');

                // Write the header row
                fputcsv($file, $columns);

                // Write each record as a CSV row
                foreach ($data as $item) {
                    $row = [];
                    foreach ($columns as $col) {
                        $row[] = $item->{$col};
                    }
                    fputcsv($file, $row);
                }
                fclose($file);
            };

            // Generate a filename with filter information
            $filename = 'bread_boost_';
            if ($startDate && $endDate) {
                $filename .= $startDate . '_to_' . $endDate;
            } else {
                $filename .= 'all_dates';
            }
            if (!empty($franchiseStores)) {
                $filename .= '_stores_' . count($franchiseStores);
            }
            $filename .= '.csv';

            Log::info('Bread Boost CSV export completed', [
                'filename' => $filename,
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Bread Boost data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Bread Boost data: ' . $e->getMessage()
            ], 500);
        }
    }
}
