<?php

namespace App\Http\Controllers\Data;

use App\Models\FinalSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ExportController extends Controller
{
    public function exportFinalSummary(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Final Summary export requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        // No changes needed to the controller code
        // The Excel formula will handle inserting the cell values

        Log::debug('Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_store' => $franchiseStore
        ]);

        // Build the query with filtering conditions
        $query = FinalSummary::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Final Summary data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores'
            ]);

            // Define the columns to export. Adjust as needed.
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'total_sales',
                'modified_order_qty',
                'refunded_order_qty',
                'customer_count',
                'phone_sales',
                'call_center_sales',
                'drive_thru_sales',
                'website_sales',
                'mobile_sales',
                'doordash_sales',
                'grubhub_sales',
                'ubereats_sales',
                'delivery_sales',
                'digital_sales_percent',
                'portal_transactions',
                'put_into_portal',
                'portal_used_percent',
                'put_in_portal_on_time',
                'in_portal_on_time_percent',
                'delivery_tips',
                'prepaid_delivery_tips',
                'in_store_tip_amount',
                'prepaid_instore_tip_amount',
                'total_tips',
                'over_short',
                'cash_sales',

                'total_waste_cost',
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

            Log::info('Final Summary CSV export completed', [
                'filename' => 'final_summary_data.csv',
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, 'final_summary_data.csv', [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Final Summary data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Final Summary data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getFinalSummaryJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Final Summary JSON data requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        Log::debug('JSON request parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_store' => $franchiseStore
        ]);

        // Build the query with filtering conditions
        $query = FinalSummary::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Final Summary JSON data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores'
            ]);

            return response()->json([
                'success' => true,
                'record_count' => $recordCount,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving Final Summary JSON data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Final Summary data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportFinalSummaryCsv(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Final Summary CSV export requested', [
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent')
        ]);

        // Get parameters from either URL segments or query parameters
        $startDate = $startDateParam ?? $request->query('start_date');
        $endDate = $endDateParam ?? $request->query('end_date');
        $franchiseStore = $franchiseStoreParam ?? $request->query('franchise_store');

        Log::debug('CSV Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_store' => $franchiseStore
        ]);

        // Build the query with filtering conditions
        $query = FinalSummary::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if ($franchiseStore) {
            $query->where('franchise_store', $franchiseStore);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Final Summary CSV data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_store' => $franchiseStore ?: 'all stores'
            ]);

            // Generate a filename with date information
            $filename = 'final_summary_';
            if ($startDate && $endDate) {
                $filename .= $startDate . '_to_' . $endDate;
            } else {
                $filename .= 'all_dates';
            }
            if ($franchiseStore) {
                $filename .= '_' . str_replace(' ', '_', $franchiseStore);
            }
            $filename .= '.csv';

            // Define the columns to export
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'total_sales',
                'modified_order_qty',
                'refunded_order_qty',
                'customer_count',
                'phone_sales',
                'call_center_sales',
                'drive_thru_sales',
                'website_sales',
                'mobile_sales',
                'doordash_sales',
                'grubhub_sales',
                'ubereats_sales',
                'delivery_sales',
                'digital_sales_percent',
                'portal_transactions',
                'put_into_portal',
                'portal_used_percent',
                'put_in_portal_on_time',
                'in_portal_on_time_percent',
                'delivery_tips',
                'prepaid_delivery_tips',
                'in_store_tip_amount',
                'prepaid_instore_tip_amount',
                'total_tips',
                'over_short',
                'cash_sales',

                'total_waste_cost',
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

            Log::info('Final Summary CSV export completed', [
                'filename' => $filename,
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Final Summary CSV data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Final Summary CSV data: ' . $e->getMessage()
            ], 500);
        }
    }
}




