<?php

namespace App\Http\Controllers\Data;

use App\Models\SummaryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ExportUpsellingController extends Controller
{
    public function exportFinalSummary(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null, $menuItemsParam = null)
    {
        Log::info('Upselling Final Summary export requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        // Get menu items as a comma-separated string and convert to array
        $menuItemNames = [];

        // First check if it was passed as a route parameter
        if (!empty($menuItemsParam)) {
            $menuItemNames = array_map('trim', explode(',', $menuItemsParam));
        } else {
            // Try menu_items parameter (from Power Query)
            $menuItemsString = $request->query('menu_items');
            if (!empty($menuItemsString)) {
                $menuItemNames = array_map('trim', explode(',', $menuItemsString));
            }

            // If that didn't work, try menu_item_name as fallback
            if (empty($menuItemNames)) {
                $menuItemParam = $request->query('menu_item_name');
                if (!empty($menuItemParam)) {
                    if (is_array($menuItemParam)) {
                        $menuItemNames = $menuItemParam;
                    } else {
                        // Check if it's a comma-separated string
                        if (strpos($menuItemParam, ',') !== false) {
                            $menuItemNames = array_map('trim', explode(',', $menuItemParam));
                        } else {
                            $menuItemNames = [$menuItemParam];
                        }
                    }
                }
            }
        }

        // Filter out empty values
        $menuItemNames = array_filter($menuItemNames, function($value) {
            return !empty($value) && $value !== 'null' && $value !== 'undefined';
        });

        Log::debug('Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_store' => $franchiseStore,
            'menu_item_names' => $menuItemNames,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = SummaryItem::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        // Filter by menu_item_name if provided
        if (!empty($menuItemNames)) {
            $query->whereIn('menu_item_name', $menuItemNames);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Upselling Summary data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores',
                'menu_items' => !empty($menuItemNames) ? implode(', ', $menuItemNames) : 'all items'
            ]);

            // Define the columns to export based on SummaryItem model
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'menu_item_name',
                'menu_item_account',
                'item_id',
                'item_quantity',
                'royalty_obligation',
                'taxable_amount',
                'non_taxable_amount',
                'tax_exempt_amount',
                'non_royalty_amount',
                'tax_included_amount',
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
            $filename = 'upselling_summary_';
            if ($startDate && $endDate) {
                $filename .= $startDate . '_to_' . $endDate;
            } else {
                $filename .= 'all_dates';
            }
            if ($franchiseStore) {
                $filename .= '_' . str_replace(' ', '_', $franchiseStore);
            }
            if (!empty($menuItemNames)) {
                $filename .= '_items_' . count($menuItemNames);
            }
            $filename .= '.csv';

            Log::info('Upselling Summary CSV export completed', [
                'filename' => $filename,
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Upselling Summary data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Upselling Summary data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getFinalSummaryJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Upselling Summary JSON data requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        // Get all menu_item_name parameters
        $menuItemNames = $request->query('menu_item_name');

        // Handle different ways the parameter might be passed
        if (is_null($menuItemNames)) {
            $menuItemNames = [];
        } elseif (is_string($menuItemNames)) {
            // Single value
            $menuItemNames = [$menuItemNames];
        }

        // Filter out empty values
        $menuItemNames = array_filter($menuItemNames, function($value) {
            return !empty($value) && $value !== 'null' && $value !== 'undefined';
        });

        Log::debug('JSON request parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_store' => $franchiseStore,
            'menu_item_names' => $menuItemNames,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = SummaryItem::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        // Filter by menu_item_name if provided
        if (!empty($menuItemNames)) {
            $query->whereIn('menu_item_name', $menuItemNames);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Upselling Summary JSON data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores',
                'menu_items' => !empty($menuItemNames) ? implode(', ', $menuItemNames) : 'all items'
            ]);

            return response()->json([
                'success' => true,
                'record_count' => $recordCount,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving Upselling Summary JSON data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Upselling Summary data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportFinalSummaryCsv(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Upselling Summary CSV export requested', [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ]);

        // 1) Read route segments OR query string
        $startDate      = $startDateParam    ?? $request->query('start_date');
        $endDate        = $endDateParam      ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        // 2) Get menu items as a comma-separated string and convert to array
        $menuItemNames = [];

        // Try menu_items parameter (from Power Query)
        $menuItemsString = $request->query('menu_items');
        if (!empty($menuItemsString)) {
            $menuItemNames = array_map('trim', explode(',', $menuItemsString));
        }

        // If that didn't work, try menu_item_name as fallback
        if (empty($menuItemNames)) {
            $menuItemParam = $request->query('menu_item_name');
            if (!empty($menuItemParam)) {
                if (is_array($menuItemParam)) {
                    $menuItemNames = $menuItemParam;
                } else {
                    // Check if it's a comma-separated string
                    if (strpos($menuItemParam, ',') !== false) {
                        $menuItemNames = array_map('trim', explode(',', $menuItemParam));
                    } else {
                        $menuItemNames = [$menuItemParam];
                    }
                }
            }
        }

        // 3) Remove any empty/null/"undefined" entries
        $menuItemNames = array_filter($menuItemNames,
            fn($v) => !empty($v) && $v !== 'null' && $v !== 'undefined'
        );

        Log::debug('CSV Export parameters', [
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'franchise_store' => $franchiseStore,
            'menu_item_names' => $menuItemNames,
            'raw_query'       => $request->getQueryString(),
            'request_all'     => $request->all(),
        ]);

        // 4) Build Eloquent query
        $query = SummaryItem::query();

        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        // Only apply menu item filter if items were specified
        if (!empty($menuItemNames)) {
            $query->whereIn('menu_item_name', $menuItemNames);
        }

        try {
            $data = $query->get();
            $recordCount = $data->count();

            Log::info('Upselling Summary CSV data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range'   => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores',
                'menu_items'   => $menuItemNames ? implode(', ', $menuItemNames) : 'all items',
            ]);

            // 5) Stream out as CSV - updated columns for SummaryItem model
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'menu_item_name',
                'menu_item_account',
                'item_id',
                'item_quantity',
                'royalty_obligation',
                'taxable_amount',
                'non_taxable_amount',
                'tax_exempt_amount',
                'non_royalty_amount',
                'tax_included_amount',
                'created_at',
                'updated_at'
            ];

            $callback = function() use ($data, $columns) {
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

            // build a sensible filename
            $filename = 'upselling_summary_'
                . ($startDate && $endDate
                    ? "{$startDate}_to_{$endDate}"
                    : 'all_dates'
                )
                . ($franchiseStore ? "_{$franchiseStore}" : '')
                . (!empty($menuItemNames) ? '_items_' . count($menuItemNames) : '')
                . '.csv';

            Log::info('Upselling Summary CSV export completed', [
                'filename'     => $filename,
                'record_count' => $recordCount,
            ]);

            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);

        } catch (\Exception $e) {
            Log::error('Error exporting Upselling Summary CSV data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
