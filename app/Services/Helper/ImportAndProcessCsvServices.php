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
        //map the columns
        $columnMap = [
        'franchise_store'   => 'franchisestore',
        'business_date'     => 'businessdate',
        'create_datetime'   => 'createdatetime',
        'verified_datetime' => 'verifieddatetime',
        'till'              => 'till',
        'check_type'        => 'checktype',
        'system_totals'     => 'systemtotals',
        'verified'          => 'verified',
        'variance'          => 'variance',
        'created_by'        => 'createdby',
        'verified_by'       => 'verifiedby',
        ];

        //set the transformers if exist
        $transformers = [
        'create_datetime'   => fn($v) => $this->parseDateTime($v),
        'verified_datetime' => fn($v) => $this->parseDateTime($v),
        ];

        // run the mapCsvToRows
        $rows =$this->mapCsvToRows($filePath,$columnMap,$transformers);

        //upsert the data
        $this->inserter->insertCashManagement($rows);

        //return the data to use
        return $rows;
    }

    public function processWaste($filePath)
    {
        $columnMap = [
        'business_date' => 'businessdate',
        'franchise_store' =>'franchisestore',
        'cv_item_id' =>'cvitemid',
        'menu_item_name' =>'menuitemname',
        'expired' =>'expired',
        'waste_date_time' =>'wastedatetime',
        'produce_date_time' => 'producedatetime',
        'waste_reason' => 'wastereason',
        'cv_order_id' => 'cvorderid',
        'waste_type' => 'wastetype',
        'item_cost' => 'itemcost',
        'quantity' => 'quantity',
        ];

        //set the transformers if exist
        $transformers = [
        'waste_date_time'   => fn($v) => $this->parseDateTime($v),
        'produce_date_time' => fn($v) => $this->parseDateTime($v),
        'expired'           => fn($v) => strtolower(trim((string)$v)) === 'yes',
        ];

        // run the mapCsvToRows
        $rows =$this->mapCsvToRows($filePath,$columnMap,$transformers);

        $this->inserter->insertWaste($rows);

        return $rows;
    }

    public function processFinancialView($filePath)
    {

        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'area' => 'area',
            'sub_account' => 'subaccount',
            'amount' => 'amount'
        ];


        // run the mapCsvToRows
        $rows =$this->mapCsvToRows($filePath,$columnMap);

        $this->inserter->insertFinancialView($rows);
        return $rows;
    }

    public function processSummaryItems($filePath)
    {
        //   Log::info('Processing Summary Items CSV file.');

        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'menu_item_name' => 'menuitemname',
            'menu_item_account' => 'menuitemaccount',
            'item_id' => 'itemid',
            'item_quantity' => 'itemquantity',
            'royalty_obligation' => 'royaltyobligation',
            'taxable_amount' => 'taxableamount',
            'non_taxable_amount' => 'nontaxableamount',
            'tax_exempt_amount' => 'taxexemptamount',
            'non_royalty_amount' => 'nonroyaltyamount',
            'tax_included_amount' => 'taxincludedamount'
        ];

        $rows =$this->mapCsvToRows($filePath,$columnMap);

        $this->inserter->insertSummaryItems($rows);
        return $rows;
    }

    public function processSummarySales($filePath)
    {
        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'royalty_obligation' => 'royaltyobligation',
            'customer_count' => 'customercount',
            'taxable_amount' => 'taxableamount',
            'non_taxable_amount' => 'nontaxableamount',
            'tax_exempt_amount' => 'taxexemptamount',
            'non_royalty_amount' => 'nonroyaltyamount',
            'refund_amount' => 'refundamount',
            'sales_tax' => 'salestax',
            'gross_sales' => 'grosssales',
            'occupational_tax' => 'occupationaltax',
            'delivery_tip' => 'deliverytip',
            'delivery_fee' => 'deliveryfee',
            'delivery_service_fee' => 'deliveryservicefee',
            'delivery_small_order_fee' => 'deliverysmallorderfee',
            'modified_order_amount' => 'modifiedorderamount',
            'store_tip_amount' => 'storetipamount',
            'prepaid_cash_orders' => 'prepaidcashorders',
            'prepaid_non_cash_orders' => 'prepaidnoncashorders',
            'prepaid_sales' => 'prepaidsales',
            'prepaid_delivery_tip' => 'prepaiddeliverytip',
            'prepaid_in_store_tip_amount' => 'prepaidinstoretipamount',
            'over_short' => 'overshort',
            'previous_day_refunds' =>'previousdayrefunds',
            'saf' => 'saf',
            'manager_notes' => 'managernotes'
        ];

        $rows =$this->mapCsvToRows($filePath,$columnMap);
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
            $columnMap = [
                'franchise_store' => 'franchisestore',
                'business_date' => 'businessdate',
                'payment_method' => 'paymentmethod',
                'sub_payment_method' => 'subpaymentmethod',
                'total_amount' => 'totalamount',
                'saf_qty' => 'safqty',
                'saf_total' => 'saftotal'
            ];

         $rows =$this->mapCsvToRows($filePath,$columnMap);
        $this->inserter->insertSummaryTransactions($rows);
        return $rows;
    }

    public function processDetailOrders($filePath)
    {

        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'date_time_placed' => 'datetimeplaced',
            'date_time_fulfilled' => 'datetimefulfilled',
            'royalty_obligation' => 'royaltyobligation',
            'quantity' => 'quantity',
            'customer_count' => 'customercount',
            'order_id' => 'orderid',
            'taxable_amount' => 'taxableamount',
            'non_taxable_amount' => 'nontaxableamount',
            'tax_exempt_amount' => 'taxexemptamount',
            'non_royalty_amount' => 'nonroyaltyamount',
            'sales_tax' => 'salestax',
            'employee' => 'employee',
            'gross_sales' => 'grosssales',
            'occupational_tax' => 'occupationaltax',
            'override_approval_employee' => 'overrideapprovalemployee',
            'order_placed_method' => 'orderplacedmethod',
            'delivery_tip' => 'deliverytip',
            'delivery_tip_tax' => 'deliverytiptax',
            'order_fulfilled_method' => 'orderfulfilledmethod',
            'delivery_fee' => 'deliveryfee',
            'modified_order_amount' => 'modifiedorderamount',
            'delivery_fee_tax' => 'deliveryfeetax',
            'modification_reason' => 'modificationreason',
            'payment_methods' => 'paymentmethods',
            'delivery_service_fee' => 'deliveryservicefee',
            'delivery_service_fee_tax' => 'deliveryservicefeetax',
            'refunded' => 'refunded',
            'delivery_small_order_fee' => 'deliverysmallorderfee',
            'delivery_small_order_fee_tax' => 'deliverysmallorderfeetax',
            'transaction_type' => 'transactiontype',
            'store_tip_amount' => 'storetipamount',
            'promise_date' => 'promisedate',
            'tax_exemption_id' => 'taxexemptionid',
            'tax_exemption_entity_name' => 'taxexemptionentityname',
            'user_id' => 'userid',
            'hnrOrder' => 'hnrorder',
            'broken_promise' => 'brokenpromise',
            'portal_eligible' => 'portaleligible',
            'portal_used' => 'portalused',
            'put_into_portal_before_promise_time' => 'putintoportalbeforepromisetime',
            'portal_compartments_used' => 'portalcompartmentsused',
            'time_loaded_into_portal' => 'timeloadedintoportal'
        ];

        $transformers = [
        'date_time_placed'   => fn($v) => $this->parseDateTime($v),
        'date_time_fulfilled' => fn($v) => $this->parseDateTime($v),
        'promise_date' => fn($v) => $this->parseDateTime($v),
        'time_loaded_into_portal' => fn($v) => $this->parseDateTime($v),
        ];

        $rows =$this->mapCsvToRows($filePath,$columnMap,$transformers);
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
        $columnMap = [
            'franchise_store' => 'franchisestore',
            'business_date' => 'businessdate',
            'date_time_placed' => 'datetimeplaced',
            'date_time_fulfilled' => 'datetimefulfilled',
            'net_amount' => 'netamount',
            'quantity' => 'quantity',
            'royalty_item' => 'royaltyitem',
            'taxable_item' => 'taxableitem',
            'order_id' => 'orderid',
            'item_id' => 'itemid',
            'menu_item_name' => 'menuitemname',
            'menu_item_account' => 'menuitemaccount',
            'bundle_name' => 'bundlename',
            'employee' => 'employee',
            'override_approval_employee' => 'overrideapprovalemployee',
            'order_placed_method' => 'orderplacedmethod',
            'order_fulfilled_method' => 'orderfulfilledmethod',
            'modified_order_amount' => 'modifiedorderamount',
            'modification_reason' => 'modificationreason',
            'payment_methods' => 'paymentmethods',
            'refunded' => 'refunded',
            'tax_included_amount' => 'taxincludedamount'
        ];

        $transformers = [
        'date_time_placed'   => fn($v) => $this->parseDateTime($v),
        'date_time_fulfilled' => fn($v) => $this->parseDateTime($v),];
        $rows =$this->mapCsvToRows($filePath,$columnMap, $transformers);
         $this->inserter->insertOrderLine($rows);

        return $rows;
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


    protected function mapCsvToRows(string $filePath, array $columnMap, array $transformers = []): array
    {
        $raw = $this->readCsv($filePath);
        $rows = [];

        foreach ($raw as $line) {
            $row = [];
            foreach ($columnMap as $columnName => $csvKey) {
                $value = $line[$csvKey] ?? null;

                // if you need to transform (e.g. parse a date field), do it:
                if (isset($transformers[$columnName])) {
                    $value = $transformers[$columnName]($value);
                }

                $row[$columnName] = $value;
            }
            $rows[] = $row;
        }

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
    /*********************  Helpers   *********************/

}
