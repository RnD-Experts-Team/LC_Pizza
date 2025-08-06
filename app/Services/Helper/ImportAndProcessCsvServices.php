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
            $createDatetime = $this->parseDateTime($row['createdatetime']);
            $verifiedDatetime = $this->parseDateTime($row['verifieddatetime']);

            $rows[] = [
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'create_datetime' => $createDatetime,
                'verified_datetime' => $verifiedDatetime,
                'till' => $row['till'],
                'check_type' => $row['checktype'],
                'system_totals' => $row['systemtotals'],
                'verified' => $row['verified'],
                'variance' => $row['variance'],
                'created_by' => $row['createdby'],
                'verified_by' => $row['verifiedby']
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
            $wasteDateTime = $this->parseDateTime($row['wastedatetime']);
            $produceDateTime = $this->parseDateTime($row['producedatetime']);

            $rows[] = [
                'business_date' => $row['businessdate'],
                'franchise_store' => $row['franchisestore'],
                'cv_item_id' => $row['cvitemid'],
                'menu_item_name' => $row['menuitemname'],
                'expired' => strtolower($row['expired']) === 'yes',
                'waste_date_time' => $wasteDateTime,
                'produce_date_time' => $produceDateTime,
                'waste_reason' => $row['wastereason'] ?? null,
                'cv_order_id' => $row['cvorderid'] ?? null,
                'waste_type' => $row['wastetype'],
                'item_cost' => $row['itemcost'],
                'quantity' => $row['quantity'],
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
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'area' => $row['area'],
                'sub_account' => $row['subaccount'],
                'amount' => $row['amount']
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
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'menu_item_name' => $row['menuitemname'],
                'menu_item_account' => $row['menuitemaccount'],
                'item_id' => $row['itemid'],
                'item_quantity' => $row['itemquantity'],
                'royalty_obligation' => $row['royaltyobligation'],
                'taxable_amount' => $row['taxableamount'],
                'non_taxable_amount' => $row['nontaxableamount'],
                'tax_exempt_amount' => $row['taxexemptamount'],
                'non_royalty_amount' => $row['nonroyaltyamount'],
                'tax_included_amount' => $row['taxincludedamount']
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
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'royalty_obligation' => $row['royaltyobligation'],
                'customer_count' => $row['customercount'],
                'taxable_amount' => $row['taxableamount'],
                'non_taxable_amount' => $row['nontaxableamount'],
                'tax_exempt_amount' => $row['taxexemptamount'],
                'non_royalty_amount' => $row['nonroyaltyamount'],
                'refund_amount' => $row['refundamount'],
                'sales_tax' => $row['salestax'],
                'gross_sales' => $row['grosssales'],
                'occupational_tax' => $row['occupationaltax'],
                'delivery_tip' => $row['deliverytip'],
                'delivery_fee' => $row['deliveryfee'],
                'delivery_service_fee' => $row['deliveryservicefee'],
                'delivery_small_order_fee' => $row['deliverysmallorderfee'],
                'modified_order_amount' => $row['modifiedorderamount'],
                'store_tip_amount' => $row['storetipamount'],
                'prepaid_cash_orders' => $row['prepaidcashorders'],
                'prepaid_non_cash_orders' => $row['prepaidnoncashorders'],
                'prepaid_sales' => $row['prepaidsales'],
                'prepaid_delivery_tip' => $row['prepaiddeliverytip'],
                'prepaid_in_store_tip_amount' => $row['prepaidinstoretipamount'],
                'over_short' => $row['overshort'],
                'previous_day_refunds' => $row['previousdayrefunds'],
                'saf' => $row['saf'],
                'manager_notes' => $row['managernotes']
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
                'sub_payment_method' => $row['subpaymentmethod'],
                'total_amount' => $row['totalamount'],
                'saf_qty' => $row['safqty'],
                'saf_total' => $row['saftotal']
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
            $dateTimePlaced = $this->parseDateTime($row['datetimeplaced']);
            $dateTimeFulfilled = $this->parseDateTime($row['datetimefulfilled']);
            $promiseDate = $this->parseDateTime($row['promisedate']);
            $timeLoadedIntoPortal = $this->parseDateTime($row['timeloadedintoportal']);

            $rows[] = [
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'date_time_placed' => $dateTimePlaced,
                'date_time_fulfilled' => $dateTimeFulfilled,
                'royalty_obligation' => $row['royaltyobligation'],
                'quantity' => $row['quantity'],
                'customer_count' => $row['customercount'],
                'order_id' => $row['orderid'],
                'taxable_amount' => $row['taxableamount'],
                'non_taxable_amount' => $row['nontaxableamount'],
                'tax_exempt_amount' => $row['taxexemptamount'],
                'non_royalty_amount' => $row['nonroyaltyamount'],
                'sales_tax' => $row['salestax'],
                'employee' => $row['employee'],
                'gross_sales' => $row['grosssales'],
                'occupational_tax' => $row['occupationaltax'],
                'override_approval_employee' => $row['overrideapprovalemployee'],
                'order_placed_method' => $row['orderplacedmethod'],
                'delivery_tip' => $row['deliverytip'],
                'delivery_tip_tax' => $row['deliverytiptax'],
                'order_fulfilled_method' => $row['orderfulfilledmethod'],
                'delivery_fee' => $row['deliveryfee'],
                'modified_order_amount' => $row['modifiedorderamount'],
                'delivery_fee_tax' => $row['deliveryfeetax'],
                'modification_reason' => $row['modificationreason'],
                'payment_methods' => $row['paymentmethods'],
                'delivery_service_fee' => $row['deliveryservicefee'],
                'delivery_service_fee_tax' => $row['deliveryservicefeetax'],
                'refunded' => $row['refunded'],
                'delivery_small_order_fee' => $row['deliverysmallorderfee'],
                'delivery_small_order_fee_tax' => $row['deliverysmallorderfeetax'],
                'transaction_type' => $row['transactiontype'],
                'store_tip_amount' => $row['storetipamount'],
                'promise_date' => $promiseDate,
                'tax_exemption_id' => $row['taxexemptionid'],
                'tax_exemption_entity_name' => $row['taxexemptionentityname'],
                'user_id' => $row['userid'],
                'hnrOrder' => $row['hnrorder'],
                'broken_promise' => $row['brokenpromise'],
                'portal_eligible' => $row['portaleligible'],
                'portal_used' => $row['portalused'],
                'put_into_portal_before_promise_time' => $row['putintoportalbeforepromisetime'],
                'portal_compartments_used' => $row['portalcompartmentsused'],
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
            $dateTimePlaced = $this->parseDateTime($row['datetimeplaced']);
            $dateTimeFulfilled = $this->parseDateTime($row['datetimefulfilled']);

            $rows[] = [
                'franchise_store' => $row['franchisestore'],
                'business_date' => $row['businessdate'],
                'date_time_placed' => $dateTimePlaced,
                'date_time_fulfilled' => $dateTimeFulfilled,
                'net_amount' => $row['netamount'],
                'quantity' => $row['quantity'],
                'royalty_item' => $row['royaltyitem'],
                'taxable_item' => $row['taxableitem'],
                'order_id' => $row['orderid'],
                'item_id' => $row['itemid'],
                'menu_item_name' => $row['menuitemname'],
                'menu_item_account' => $row['menuitemaccount'],
                'bundle_name' => $row['bundlename'],
                'employee' => $row['employee'],
                'override_approval_employee' => $row['overrideapprovalemployee'],
                'order_placed_method' => $row['orderplacedmethod'],
                'order_fulfilled_method' => $row['orderfulfilledmethod'],
                'modified_order_amount' => $row['modifiedorderamount'],
                'modification_reason' => $row['modificationreason'],
                'payment_methods' => $row['paymentmethods'],
                'refunded' => $row['refunded'],
                'tax_included_amount' => $row['taxincludedamount']
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
    private function readCsv($filePath)
    {
        $data = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle, 1000, ',');

            // Normalize header: trim, lowercase, and remove all spaces
            $normalizedHeader = array_map(function ($key) {
                return str_replace(' ', '', strtolower(trim($key)));
            }, $header);

            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($row) == count($normalizedHeader)) {
                    // Just trim the row values (optional, depending on your use case)
                    $trimmedValues = array_map('trim', $row);

                    $normalizedRow = array_combine($normalizedHeader, $trimmedValues);
                    $data[] = $normalizedRow;
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
