<?php

namespace App\Http\Controllers\Data;

use App\Models\FinanceData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ExportFinanceController extends Controller
{
    public function exportCSVs(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Finance Data CSV export requested', [
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

        Log::debug('Finance Export parameters', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query' => $request->getQueryString()
        ]);

        // Build the query with filtering conditions
        $query = FinanceData::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Finance data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range' => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_stores' => !empty($franchiseStores) ? implode(', ', $franchiseStores) : 'all stores'
            ]);

            // Define the columns to export based on FinanceData model
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'Pizza_Carryout',
                'HNR_Carryout',
                'Bread_Carryout',
                'Wings_Carryout',
                'Beverages_Carryout',
                'Other_Foods_Carryout',
                'Side_Items_Carryout',
                'Pizza_Delivery',
                'HNR_Delivery',
                'Bread_Delivery',
                'Wings_Delivery',
                'Beverages_Delivery',
                'Other_Foods_Delivery',
                'Side_Items_Delivery',
                'Delivery_Charges',
                'TOTAL_Net_Sales',
                'Customer_Count',
                'Gift_Card_Non_Royalty',
                'Total_Non_Royalty_Sales',
                'Total_Non_Delivery_Tips',
                'TOTAL_Sales_TaxQuantity',
                'DELIVERY_Quantity',
                'Delivery_Fee',
                'Delivery_Service_Fee',
                'Delivery_Small_Order_Fee',
                'Delivery_Late_to_Portal_Fee',
                'TOTAL_Native_App_Delivery_Fees',
                'Delivery_Tips',
                'DoorDash_Quantity',
                'DoorDash_Order_Total',
                'Grubhub_Quantity',
                'Grubhub_Order_Total',
                'Uber_Eats_Quantity',
                'Uber_Eats_Order_Total',
                'ONLINE_ORDERING_Mobile_Order_Quantity',
                'ONLINE_ORDERING_Online_Order_Quantity',
                'ONLINE_ORDERING_Pay_In_Store',
                'Agent_Pre_Paid',
                'Agent_Pay_InStore',
                'AI_Pre_Paid',
                'AI_Pay_InStore',
                'PrePaid_Cash_Orders',
                'PrePaid_Non_Cash_Orders',
                'PrePaid_Sales',
                'Prepaid_Delivery_Tips',
                'Prepaid_InStore_Tips',
                'Marketplace_from_Non_Cash_Payments_box',
                'AMEX',
                'Total_Non_Cash_Payments',
                'credit_card_Cash_Payments',
                'Debit_Cash_Payments',
                'epay_Cash_Payments',
                'Non_Cash_Payments',
                'Cash_Sales',
                'Cash_Drop_Total',
                'Over_Short',
                'Payouts',
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
            $filename = 'finance_data_';
            if ($startDate && $endDate) {
                $filename .= $startDate . '_to_' . $endDate;
            } else {
                $filename .= 'all_dates';
            }
            if (!empty($franchiseStores)) {
                $filename .= '_stores_' . count($franchiseStores);
            }
            $filename .= '.csv';

            Log::info('Finance Data CSV export completed', [
                'filename' => $filename,
                'record_count' => $recordCount
            ]);

            // Return a streaming download response using Laravel's streamDownload method
            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting Finance data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export Finance data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportJson(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Finance Data JSON data requested', [
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
        $query = FinanceData::query();

        // Filter by business_date between startDate and endDate if both are provided
        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            // Retrieve the filtered data
            $data = $query->get();

            $recordCount = $data->count();
            Log::info('Finance Data JSON data retrieved successfully', [
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
            Log::error('Error retrieving Finance Data JSON data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Finance data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportToExcel(Request $request, $startDateParam = null, $endDateParam = null, $franchiseStoreParam = null)
    {
        Log::info('Finance Data Excel export requested', [
            'ip'         => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
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

        Log::debug('Excel Export parameters', [
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'franchise_stores' => $franchiseStores,
            'raw_query'       => $request->getQueryString(),
            'request_all'     => $request->all(),
        ]);

        // Build Eloquent query
        $query = FinanceData::query();

        if ($startDate && $endDate) {
            $query->whereBetween('business_date', [$startDate, $endDate]);
        }

        // Filter by franchise_store if provided
        if (!empty($franchiseStores)) {
            $query->whereIn('franchise_store', $franchiseStores);
        }

        try {
            $data = $query->get();
            $recordCount = $data->count();

            Log::info('Finance Data Excel data retrieved successfully', [
                'record_count' => $recordCount,
                'date_range'   => $startDate && $endDate ? "$startDate to $endDate" : 'all dates',
                'franchise_stores' => !empty($franchiseStores) ? implode(', ', $franchiseStores) : 'all stores',
            ]);

            // Define the columns to export based on FinanceData model
            $columns = [
                'id',
                'franchise_store',
                'business_date',
                'Pizza_Carryout',
                'HNR_Carryout',
                'Bread_Carryout',
                'Wings_Carryout',
                'Beverages_Carryout',
                'Other_Foods_Carryout',
                'Side_Items_Carryout',
                'Pizza_Delivery',
                'HNR_Delivery',
                'Bread_Delivery',
                'Wings_Delivery',
                'Beverages_Delivery',
                'Other_Foods_Delivery',
                'Side_Items_Delivery',
                'Delivery_Charges',
                'TOTAL_Net_Sales',
                'Customer_Count',
                'Gift_Card_Non_Royalty',
                'Total_Non_Royalty_Sales',
                'Total_Non_Delivery_Tips',
                'TOTAL_Sales_TaxQuantity',
                'DELIVERY_Quantity',
                'Delivery_Fee',
                'Delivery_Service_Fee',
                'Delivery_Small_Order_Fee',
                'Delivery_Late_to_Portal_Fee',
                'TOTAL_Native_App_Delivery_Fees',
                'Delivery_Tips',
                'DoorDash_Quantity',
                'DoorDash_Order_Total',
                'Grubhub_Quantity',
                'Grubhub_Order_Total',
                'Uber_Eats_Quantity',
                'Uber_Eats_Order_Total',
                'ONLINE_ORDERING_Mobile_Order_Quantity',
                'ONLINE_ORDERING_Online_Order_Quantity',
                'ONLINE_ORDERING_Pay_In_Store',
                'Agent_Pre_Paid',
                'Agent_Pay_InStore',
                'AI_Pre_Paid',
                'AI_Pay_InStore',
                'PrePaid_Cash_Orders',
                'PrePaid_Non_Cash_Orders',
                'PrePaid_Sales',
                'Prepaid_Delivery_Tips',
                'Prepaid_InStore_Tips',
                'Marketplace_from_Non_Cash_Payments_box',
                'AMEX',
                'Total_Non_Cash_Payments',
                'credit_card_Cash_Payments',
                'Debit_Cash_Payments',
                'epay_Cash_Payments',
                'Non_Cash_Payments',
                'Cash_Sales',
                'Cash_Drop_Total',
                'Over_Short',
                'Payouts',
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
            $filename = 'finance_data_'
                . ($startDate && $endDate
                    ? "{$startDate}_to_{$endDate}"
                    : 'all_dates'
                )
                . (!empty($franchiseStores) ? "_stores_" . count($franchiseStores) : '')
                . '.csv';

            Log::info('Finance Data Excel export completed', [
                'filename'     => $filename,
                'record_count' => $recordCount,
            ]);

            return response()->streamDownload($callback, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);

        } catch (\Exception $e) {
            Log::error('Error exporting Finance Data Excel data', [
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
