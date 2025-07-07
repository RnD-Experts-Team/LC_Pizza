<?php

namespace App\Services\Helper;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\Helper\InsertDataServices;

// this service is used for the request -> download the ZIP file -> save the files as CSVs -> get data from them ->save them inthe db tables -> delete the files
class ImportAndProcessCsvServices {

    protected InsertDataServices $inserter;

    // Constructor for $inserter
    public function __construct(InsertDataServices $inserter)
    {
        $this->inserter = $inserter;

    }


    public function processCsvFiles($extractPath, $selectedDate)
    {
        // Log::info('Starting to process CSV files.');

        $csvFiles = [
            'Cash-Management' => 'processCashManagement',
            'SalesEntryForm-FinancialView' => 'processFinancialView',
            'Summary-Items' => 'processSummaryItems',
            'Summary-Sales' => 'processSummarySales',
            'Summary-Transactions' => 'processSummaryTransactions',
            'Detail-Orders' => 'processDetailOrders',
            'Waste-Report' => 'processWaste',
            'Detail-OrderLines' => 'processOrderLine'
        ];

        $allData = [];

        foreach ($csvFiles as $filePrefix => $processorMethod) {
            $fileNamePattern = $filePrefix . '-*_' . $selectedDate . '.csv';
            $files = glob($extractPath . DIRECTORY_SEPARATOR . $fileNamePattern);

            foreach ($files as $filePath) {
                Log::info('Processing file: ' . $filePath);
                $processed = $this->$processorMethod($filePath);
                $allData[$processorMethod] = array_merge($allData[$processorMethod] ?? [], $processed);
            }
        }

        return $allData;  // data for summary building
    }

    //********* files prossessing functions ***********/
    public function processCashManagement($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            $createDatetime = $this->parseDateTime($row['CreateDatetime']);
            $verifiedDatetime = $this->parseDateTime($row['VerifiedDatetime']);

            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'create_datetime' => $createDatetime,
                'verified_datetime' => $verifiedDatetime,
                'till' => $row['Till'],
                'check_type' => $row['CheckType'],
                'system_totals' => $row['SystemTotals'],
                'verified' => $row['Verified'],
                'variance' => $row['Variance'],
                'created_by' => $row['CreatedBy'],
                'verified_by' => $row['VerifiedBy']
            ];
        }
        $this->inserter->insertCashManagement($rows);
        // foreach (array_chunk($rows, 500) as $batch) {
        //     CashManagement::upsert(
        //         $batch,
        //         ['franchise_store', 'business_date', 'create_datetime', 'till', 'check_type'],
        //         ['verified_datetime', 'system_totals', 'verified', 'variance', 'created_by', 'verified_by']
        //     );
        // }

        return $rows;
    }

    public function processWaste($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            $wasteDateTime = $this->parseDateTime($row['WasteDateTime']);
            $produceDateTime = $this->parseDateTime($row['ProduceDateTime']);

            $rows[] = [
                'business_date' => $row['BusinessDate'],
                'franchise_store' => $row['FranchiseStore'],
                'cv_item_id' => $row['CVItemId'],
                'menu_item_name' => $row['MenuItemName'],
                'expired' => strtolower($row['Expired']) === 'yes',
                'waste_date_time' => $wasteDateTime,
                'produce_date_time' => $produceDateTime,
                'waste_reason' => $row['WasteReason'] ?? null,
                'cv_order_id' => $row['CVOrderId'] ?? null,
                'waste_type' => $row['WasteType'],
                'item_cost' => $row['ItemCost'],
                'quantity' => $row['Quantity'],
            ];
        }
        $this->inserter->insertWaste($rows);

        return $rows;
    }

    public function processFinancialView($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'area' => $row['Area'],
                'sub_account' => $row['SubAccount'],
                'amount' => $row['Amount']
            ];
        }

        $this->inserter->insertFinancialView($rows);
        return $rows;
    }

    public function processSummaryItems($filePath)
    {
        //   Log::info('Processing Summary Items CSV file.');
        $data = $this->readCsv($filePath);
        $rows = [];
        foreach ($data as $row) {
            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'menu_item_name' => $row['MenuItemName'],
                'menu_item_account' => $row['MenuItemAccount'],
                'item_id' => $row['ItemId'],
                'item_quantity' => $row['ItemQuantity'],
                'royalty_obligation' => $row['RoyaltyObligation'],
                'taxable_amount' => $row['TaxableAmount'],
                'non_taxable_amount' => $row['NonTaxableAmount'],
                'tax_exempt_amount' => $row['TaxExemptAmount'],
                'non_royalty_amount' => $row['NonRoyaltyAmount'],
                'tax_included_amount' => $row['TaxIncludedAmount']
            ];
        }
        $this->inserter->insertSummaryItems($rows);
        return $rows;
    }

    public function processSummarySales($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'royalty_obligation' => $row['RoyaltyObligation'],
                'customer_count' => $row['CustomerCount'],
                'taxable_amount' => $row['TaxableAmount'],
                'non_taxable_amount' => $row['NonTaxableAmount'],
                'tax_exempt_amount' => $row['TaxExemptAmount'],
                'non_royalty_amount' => $row['NonRoyaltyAmount'],
                'refund_amount' => $row['RefundAmount'],
                'sales_tax' => $row['SalesTax'],
                'gross_sales' => $row['GrossSales'],
                'occupational_tax' => $row['OccupationalTax'],
                'delivery_tip' => $row['DeliveryTip'],
                'delivery_fee' => $row['DeliveryFee'],
                'delivery_service_fee' => $row['DeliveryServiceFee'],
                'delivery_small_order_fee' => $row['DeliverySmallOrderFee'],
                'modified_order_amount' => $row['ModifiedOrderAmount'],
                'store_tip_amount' => $row['StoreTipAmount'],
                'prepaid_cash_orders' => $row['PrepaidCashOrders'],
                'prepaid_non_cash_orders' => $row['PrepaidNonCashOrders'],
                'prepaid_sales' => $row['PrepaidSales'],
                'prepaid_delivery_tip' => $row['PrepaidDeliveryTip'],
                'prepaid_in_store_tip_amount' => $row['PrepaidInStoreTipAmount'],
                'over_short' => $row['OverShort'],
                'previous_day_refunds' => $row['PreviousDayRefunds'],
                'saf' => $row['SAF'],
                'manager_notes' => $row['ManagerNotes']
            ];
        }

        $this->inserter->insertSummarySale($rows);
        // foreach (array_chunk($rows, 500) as $batch) {
        //     SummarySale::upsert(
        //         $batch,
        //         ['franchise_store', 'business_date'],
        //         [
        //             'royalty_obligation',
        //             'customer_count',
        //             'taxable_amount',
        //             'non_taxable_amount',
        //             'tax_exempt_amount',
        //             'non_royalty_amount',
        //             'refund_amount',
        //             'sales_tax',
        //             'gross_sales',
        //             'occupational_tax',
        //             'delivery_tip',
        //             'delivery_fee',
        //             'delivery_service_fee',
        //             'delivery_small_order_fee',
        //             'modified_order_amount',
        //             'store_tip_amount',
        //             'prepaid_cash_orders',
        //             'prepaid_non_cash_orders',
        //             'prepaid_sales',
        //             'prepaid_delivery_tip',
        //             'prepaid_in_store_tip_amount',
        //             'over_short',
        //             'previous_day_refunds',
        //             'saf',
        //             'manager_notes'
        //         ]
        //     );
        // }

        return $rows;
    }

    public function processSummaryTransactions($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            $rows[] = [
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'payment_method' => $row['paymentmethod'],
                'sub_payment_method' => $row['SubPaymentMethod'],
                'total_amount' => $row['TotalAmount'],
                'saf_qty' => $row['SAFQty'],
                'saf_total' => $row['SAFTotal']
            ];
        }
        $this->inserter->insertSummaryTransactions($rows);
        return $rows;
    }

    public function processDetailOrders($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            // Parse datetime fields
            $dateTimePlaced = $this->parseDateTime($row['DateTimePlaced']);
            $dateTimeFulfilled = $this->parseDateTime($row['DateTimeFulfilled']);
            $promiseDate = $this->parseDateTime($row['PromiseDate']);
            $timeLoadedIntoPortal = $this->parseDateTime($row['Time Loaded into Portal']);

            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'date_time_placed' => $dateTimePlaced,
                'date_time_fulfilled' => $dateTimeFulfilled,
                'royalty_obligation' => $row['RoyaltyObligation'],
                'quantity' => $row['Quantity'],
                'customer_count' => $row['CustomerCount'],
                'order_id' => $row['OrderId'],
                'taxable_amount' => $row['TaxableAmount'],
                'non_taxable_amount' => $row['NonTaxableAmount'],
                'tax_exempt_amount' => $row['TaxExemptAmount'],
                'non_royalty_amount' => $row['NonRoyaltyAmount'],
                'sales_tax' => $row['SalesTax'],
                'employee' => $row['Employee'],
                'gross_sales' => $row['GrossSales'],
                'occupational_tax' => $row['OccupationalTax'],
                'override_approval_employee' => $row['OverrideApprovalEmployee'],
                'order_placed_method' => $row['OrderPlacedMethod'],
                'delivery_tip' => $row['DeliveryTip'],
                'delivery_tip_tax' => $row['DeliveryTipTax'],
                'order_fulfilled_method' => $row['OrderFulfilledMethod'],
                'delivery_fee' => $row['DeliveryFee'],
                'modified_order_amount' => $row['ModifiedOrderAmount'],
                'delivery_fee_tax' => $row['DeliveryFeeTax'],
                'modification_reason' => $row['ModificationReason'],
                'payment_methods' => $row['PaymentMethods'],
                'delivery_service_fee' => $row['DeliveryServiceFee'],
                'delivery_service_fee_tax' => $row['DeliveryServiceFeeTax'],
                'refunded' => $row['Refunded'],
                'delivery_small_order_fee' => $row['DeliverySmallOrderFee'],
                'delivery_small_order_fee_tax' => $row['DeliverySmallOrderFeeTax'],
                'transaction_type' => $row['TransactionType'],
                'store_tip_amount' => $row['StoreTipAmount'],
                'promise_date' => $promiseDate,
                'tax_exemption_id' => $row['TaxExemptionId'],
                'tax_exemption_entity_name' => $row['TaxExemptionEntityName'],
                'user_id' => $row['UserId'],
                'hnrOrder' => $row['hnrOrder'],
                'broken_promise' => $row['Broken Promise'],
                'portal_eligible' => $row['PortalEligible'],
                'portal_used' => $row['PortalUsed'],
                'put_into_portal_before_promise_time' => $row['Put into Portal before PromiseTime'],
                'portal_compartments_used' => $row['Portal Compartments Used'],
                'time_loaded_into_portal' => $timeLoadedIntoPortal
            ];
        }

        $this->inserter->insertDetailOrders($rows);

        //     DetailOrder::upsert(
        //         $batch,
        //         ['franchise_store', 'business_date', 'order_id'],
        //         [
        //             'date_time_placed',
        //             'date_time_fulfilled',
        //             'royalty_obligation',
        //             'quantity',
        //             'customer_count',
        //             'taxable_amount',
        //             'non_taxable_amount',
        //             'tax_exempt_amount',
        //             'non_royalty_amount',
        //             'sales_tax',
        //             'employee',
        //             'gross_sales',
        //             'occupational_tax',
        //             'override_approval_employee',
        //             'order_placed_method',
        //             'delivery_tip',
        //             'delivery_tip_tax',
        //             'order_fulfilled_method',
        //             'delivery_fee',
        //             'modified_order_amount',
        //             'delivery_fee_tax',
        //             'modification_reason',
        //             'payment_methods',
        //             'delivery_service_fee',
        //             'delivery_service_fee_tax',
        //             'refunded',
        //             'delivery_small_order_fee',
        //             'delivery_small_order_fee_tax',
        //             'transaction_type',
        //             'store_tip_amount',
        //             'promise_date',
        //             'tax_exemption_id',
        //             'tax_exemption_entity_name',
        //             'user_id',
        //             'hnrOrder',
        //             'broken_promise',
        //             'portal_eligible',
        //             'portal_used',
        //             'put_into_portal_before_promise_time',
        //             'portal_compartments_used',
        //             'time_loaded_into_portal'
        //         ]
        //     );
        // }

        return $rows;
    }

    public function processOrderLine($filePath)
    {
        $data = $this->readCsv($filePath);
        $rows = [];

        foreach ($data as $row) {
            $dateTimePlaced = $this->parseDateTime($row['DateTimePlaced']);
            $dateTimeFulfilled = $this->parseDateTime($row['DateTimeFulfilled']);

            $rows[] = [
                'franchise_store' => $row['FranchiseStore'],
                'business_date' => $row['BusinessDate'],
                'date_time_placed' => $dateTimePlaced,
                'date_time_fulfilled' => $dateTimeFulfilled,
                'net_amount' => $row['NetAmount'],
                'quantity' => $row['Quantity'],
                'royalty_item' => $row['RoyaltyItem'],
                'taxable_item' => $row['TaxableItem'],
                'order_id' => $row['OrderId'],
                'item_id' => $row['ItemId'],
                'menu_item_name' => $row['MenuItemName'],
                'menu_item_account' => $row['MenuItemAccount'],
                'bundle_name' => $row['BundleName'],
                'employee' => $row['Employee'],
                'override_approval_employee' => $row['OverrideApprovalEmployee'],
                'order_placed_method' => $row['OrderPlacedMethod'],
                'order_fulfilled_method' => $row['OrderFulfilledMethod'],
                'modified_order_amount' => $row['ModifiedOrderAmount'],
                'modification_reason' => $row['ModificationReason'],
                'payment_methods' => $row['PaymentMethods'],
                'refunded' => $row['Refunded'],
                'tax_included_amount' => $row['TaxIncludedAmount']
            ];
        }

         $this->inserter->insertOrderLine($rows);

        //     OrderLine::upsert(
        //         $batch,
        //         ['franchise_store', 'business_date', 'order_id', 'item_id'],
        //         [
        //             'date_time_placed',
        //             'date_time_fulfilled',
        //             'net_amount',
        //             'quantity',
        //             'royalty_item',
        //             'taxable_item',
        //             'menu_item_name',
        //             'menu_item_account',
        //             'bundle_name',
        //             'employee',
        //             'override_approval_employee',
        //             'order_placed_method',
        //             'order_fulfilled_method',
        //             'modified_order_amount',
        //             'modification_reason',
        //             'payment_methods',
        //             'refunded',
        //             'tax_included_amount'
        //         ]
        //     );
        // }

        return $rows;
    }

    //****** helping functions **********/
    public function readCsv($filePath)
    {
        $data = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle, 1000, ',');
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($row) == count($header)) {
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }
        return $data;
    }

    public function generateNonce()
    {
        // Replicating the GetNonce() function in the Postman script
        $nonce = strtolower(string: bin2hex(random_bytes(16)));
        // Log::info('Generated nonce: ' . $nonce);
        return $nonce;
    }

    public function prepareRequestUrlForSignature($requestUrl)
    {
        // Replace any {{variable}} in the URL if necessary
        $requestUrl = preg_replace_callback('/{{(\w*)}}/', function ($matches) {
            return env($matches[1], '');
        }, $requestUrl);

        // Encode and lowercase the URL
        $encodedUrl = strtolower(rawurlencode($requestUrl));
        //  Log::info('Encoded request URL: ' . $encodedUrl);
        return $encodedUrl;
    }

    public function parseDateTime($dateTimeString)
    {
        if (empty($dateTimeString)) {
            return null;
        }

        $dateTimeString = Str::of($dateTimeString)
        ->replace('Z', '')
        ->trim();

        try {
            $dt = Carbon::createFromFormat('m-d-Y h:i:s A', $dateTimeString);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // fallback to generic parse if needed
        }
         try {
            $dt = Carbon::parse($dateTimeString);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::error("Error parsing datetime string: {$dateTimeString} - {$e->getMessage()}");
            return null;
        }
    }

    // Optional method to delete the extracted files
    public function deleteDirectory($dirPath)
    {
        if (!is_dir($dirPath)) {
            return;
        }
        $files = scandir($dirPath);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $fullPath = $dirPath . DIRECTORY_SEPARATOR . $file;
                if (is_dir($fullPath)) {
                    $this->deleteDirectory($fullPath);
                } else {
                    unlink($fullPath);
                }
            }
        }
        rmdir($dirPath);
    }
}
