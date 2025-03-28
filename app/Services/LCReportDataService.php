<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;


use App\Models\CashManagement;
use App\Models\FinancialView;
use App\Models\SummaryItem;
use App\Models\SummarySale;
use App\Models\SummaryTransaction;
use App\Models\DetailOrder;
use App\Models\Waste;

class LCReportDataService
{
    public function importReportData($selectedDate)
    {
        $client = new Client();

        Log::info('Starting report data import process for date: ' . $selectedDate);

        // Step 1: Generate Bearer Token
        try {
            Log::info('Attempting to generate Bearer Token');

            $response = $client->post(config('services.lcegateway.portal_server') . '/Token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'UserName'   => config('services.lcegateway.username'),
                    'Password'   => config('services.lcegateway.password'),
                ],
                'headers' => [
                    'Accept'       => 'application/json,text/plain,*/*',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            Log::info('Bearer Token response body: ' . json_encode($body));

            $accessToken = $body['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('Failed to obtain access token: access_token is missing in the response.');
                return false;
            }

            Log::info('Successfully obtained access token.');

        } catch (RequestException $e) {
            Log::error('Error obtaining access token: ' . $e->getMessage());
            return false;
        }

        // Step 2: Download the Report
        try {
            Log::info('Preparing to download the report.');

            // Prepare variables
            $httpMethod = 'GET';
            $endpoint   = '/GetReportBlobs';
            $userName   = config('services.lcegateway.username');

            // Use the static storeId
            $storeId    = '03795';

            Log::info('Using userName: ' . $userName);
            Log::info('Using static storeId: ' . $storeId);

            // Use the selected date
            $fileName = $storeId . '_' . $selectedDate . '.zip';
            Log::info('Constructed fileName: ' . $fileName);

            $queryParams = [
                'userName' => $userName,
                'fileName' => $fileName,
            ];

            $requestUrl = config('services.lcegateway.portal_server') . $endpoint . '?' . http_build_query($queryParams);
            Log::info('Constructed request URL: ' . $requestUrl);

            // Build the URL for the signature
            $encodedRequestUrl = $this->prepareRequestUrlForSignature($requestUrl);
            Log::info('Encoded request URL for signature: ' . $encodedRequestUrl);

            // Generate timestamp and nonce
            $requestTimeStamp = time();
            $nonce            = $this->generateNonce();

            Log::info('Generated request timestamp: ' . $requestTimeStamp);
            Log::info('Generated nonce: ' . $nonce);

            // For GET requests, bodyHash is empty
            $bodyHash = '';

            // Prepare signature raw data
            $appId            = config('services.lcegateway.hmac_user');
            $apiKey           = config('services.lcegateway.hmac_key');
            $signatureRawData = $appId . $httpMethod . $encodedRequestUrl . $requestTimeStamp . $nonce . $bodyHash;

            Log::info('Signature raw data: ' . $signatureRawData);

            // Compute HMAC SHA256
            $key          = base64_decode($apiKey);
            Log::info('Decoded API key from Base64.');
            $hash         = hash_hmac('sha256', $signatureRawData, $key, true);
            Log::info('Computed HMAC SHA256 hash.');
            $hashInBase64 = base64_encode($hash);
            Log::info('Encoded hash in Base64: ' . $hashInBase64);

            // Prepare the authorization header
            $authHeader = 'amx ' . $appId . ':' . $hashInBase64 . ':' . $nonce . ':' . $requestTimeStamp;
            Log::info('Constructed HMacAuthorizationHeader: ' . $authHeader);

            // Make the GET request to download the report
            Log::info('Making GET request to download the report.');

            $response = $client->get($requestUrl, [
                'headers' => [
                    'HMacAuthorizationHeader' => $authHeader,
                    'Content-Type'            => 'application/json',
                    'Authorization'           => 'bearer ' . $accessToken,
                ],
                'stream' => true,
            ]);

            Log::info('Received response from report download request.');

            // Determine the content type
            $contentType = $response->getHeaderLine('Content-Type');
            Log::info('Response content type: ' . $contentType);

            // Read the response body as a string
            $bodyString = $response->getBody()->getContents();
            Log::info('Response body string: ' . $bodyString);

            // Decode the response body
            $decodedBodyOnce = json_decode($bodyString, true);
            Log::info('Decoded body after first json_decode: ' . json_encode($decodedBodyOnce));

            if (is_string($decodedBodyOnce)) {
                // Decode again
                $decodedBody = json_decode($decodedBodyOnce, true);
                Log::info('Decoded body after second json_decode: ' . json_encode($decodedBody));
            } else {
                $decodedBody = $decodedBodyOnce;
            }

            // Check if we have 'ReportBlobUri' in the response
            if (isset($decodedBody[0]['ReportBlobUri'])) {
                $downloadUrl = $decodedBody[0]['ReportBlobUri'];
                Log::info('Download URL: ' . $downloadUrl);

                // Download the file from the provided URL
                $fileResponse = $client->get($downloadUrl, [
                    'stream' => true,
                ]);

                Log::info('Successfully downloaded the file from the provided URL.');

                // Ensure the storage/app directory exists
                $storageAppPath = storage_path('app');
                if (!file_exists($storageAppPath)) {
                    mkdir($storageAppPath, 0775, true);
                    Log::info('Created directory: ' . $storageAppPath);
                }

                // Define the temporary zip path
                $tempZipPath = $storageAppPath . DIRECTORY_SEPARATOR . 'temp_report_' . time() . '.zip';

                // Write the downloaded content to the temporary zip file
                file_put_contents($tempZipPath, $fileResponse->getBody()->getContents());
                Log::info('Saved zip file to: ' . $tempZipPath);

                // Extract the zip file
                $zip = new \ZipArchive();
                if ($zip->open($tempZipPath) === true) {
                    $extractPath = $storageAppPath . DIRECTORY_SEPARATOR . 'temp_report_' . time();
                    $zip->extractTo($extractPath);
                    $zip->close();
                    Log::info('Extracted zip file to: ' . $extractPath);

                    // Process the CSV files
                    $this->processCsvFiles($extractPath, $selectedDate);

                    // Delete temporary files
                    unlink($tempZipPath);
                    // Optionally delete extracted files
                    $this->deleteDirectory($extractPath);

                    Log::info('Successfully processed CSV files.');

                    return true;
                } else {
                    Log::error('Failed to open zip file.');
                    return false;
                }
            } else {
                Log::error('Failed to retrieve the report file. ReportBlobUri not found in response body.');
                return false;
            }

        } catch (RequestException $e) {
            Log::error('Error downloading report: ' . $e->getMessage());
            return false;
        }
    }

    private function processCsvFiles($extractPath, $selectedDate)
    {
        Log::info('Starting to process CSV files.');

        // Define the CSV files and their corresponding processors
        $csvFiles = [
            'Cash-Management' => 'processCashManagement',
            'SalesEntryForm-FinancialView' => 'processFinancialView',
            'Summary-Items' => 'processSummaryItems',
            'Summary-Sales' => 'processSummarySales',
            'Summary-Transactions' => 'processSummaryTransactions',
            'Detail-Orders' => 'processDetailOrders',
            'Waste-Report'=>'processWaste'
        ];

        foreach ($csvFiles as $filePrefix => $processorMethod) {
            $fileNamePattern = $filePrefix . '-*_' . $selectedDate . '.csv';
            $files = glob($extractPath . DIRECTORY_SEPARATOR . $fileNamePattern);

            foreach ($files as $filePath) {
                Log::info('Processing file: ' . $filePath);
                $this->$processorMethod($filePath);
            }
        }
    }

    private function processCashManagement($filePath)
    {
        Log::info('Processing Cash Management CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            // Parse the datetime strings
            $createDatetime = $this->parseDateTime($row['CreateDatetime']);
            $verifiedDatetime = $this->parseDateTime($row['VerifiedDatetime']);

            CashManagement::create([
                'franchise_store'   => $row['FranchiseStore'],
                'business_date'     => $row['BusinessDate'],
                'create_datetime'   => $createDatetime,
                'verified_datetime' => $verifiedDatetime,
                'till'              => $row['Till'],
                'check_type'        => $row['CheckType'],
                'system_totals'     => $row['SystemTotals'],
                'verified'          => $row['Verified'],
                'variance'          => $row['Variance'],
                'created_by'        => $row['CreatedBy'],
                'verified_by'       => $row['VerifiedBy']
            ]);
        }
    }

    private function processWaste($filePath)
{
    Log::info('Processing Waste CSV file.');
    $data = $this->readCsv($filePath);

    foreach ($data as $row) {
        // Parse the datetime strings
        $wasteDateTime = $this->parseDateTime($row['WasteDateTime']);
        $produceDateTime = $this->parseDateTime($row['ProduceDateTime']);

        waste::create([
            'business_date'    => $row['BusinessDate'],
            'franchise_store'  => $row['FranchiseStore'],
            'cv_item_id'       => $row['CVItemId'],
            'menu_item_name'   => $row['MenuItemName'],
            'expired'          => strtolower($row['Expired']) === 'yes',
            'waste_date_time'  => $wasteDateTime,
            'produce_date_time'=> $produceDateTime,
            'waste_reason'     => $row['WasteReason'] ?? null,
            'cv_order_id'      => $row['CVOrderId'] ?? null,
            'waste_type'       => $row['WasteType'],
            'item_cost'        => $row['ItemCost'],
            'quantity'         => $row['Quantity'],
            'age_in_minutes'   => $row['AgeInMinutes'],
        ]);
    }
}


    private function processFinancialView($filePath)
    {
        Log::info('Processing Financial View CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            FinancialView::create([
                'franchise_store' => $row['FranchiseStore'],
                'business_date'   => $row['BusinessDate'],
                'area'            => $row['Area'],
                'sub_account'     => $row['SubAccount'],
                'amount'          => $row['Amount']
            ]);
        }
    }

    private function processSummaryItems($filePath)
    {
        Log::info('Processing Summary Items CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            SummaryItem::create([
                'franchise_store'    => $row['FranchiseStore'],
                'business_date'      => $row['BusinessDate'],
                'menu_item_name'     => $row['MenuItemName'],
                'menu_item_account'  => $row['MenuItemAccount'],
                'item_id'            => $row['ItemId'],
                'item_quantity'      => $row['ItemQuantity'],
                'royalty_obligation' => $row['RoyaltyObligation'],
                'taxable_amount'     => $row['TaxableAmount'],
                'non_taxable_amount' => $row['NonTaxableAmount'],
                'tax_exempt_amount'  => $row['TaxExemptAmount'],
                'non_royalty_amount' => $row['NonRoyaltyAmount'],
                'tax_included_amount'=> $row['TaxIncludedAmount']
            ]);
        }
    }

    private function processSummarySales($filePath)
    {
        Log::info('Processing Summary Sales CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            SummarySale::create([
                'franchise_store'            => $row['FranchiseStore'],
                'business_date'              => $row['BusinessDate'],
                'royalty_obligation'         => $row['RoyaltyObligation'],
                'customer_count'             => $row['CustomerCount'],
                'taxable_amount'             => $row['TaxableAmount'],
                'non_taxable_amount'         => $row['NonTaxableAmount'],
                'tax_exempt_amount'          => $row['TaxExemptAmount'],
                'non_royalty_amount'         => $row['NonRoyaltyAmount'],
                'refund_amount'              => $row['RefundAmount'],
                'sales_tax'                  => $row['SalesTax'],
                'gross_sales'                => $row['GrossSales'],
                'occupational_tax'           => $row['OccupationalTax'],
                'delivery_tip'               => $row['DeliveryTip'],
                'delivery_fee'               => $row['DeliveryFee'],
                'delivery_service_fee'       => $row['DeliveryServiceFee'],
                'delivery_small_order_fee'   => $row['DeliverySmallOrderFee'],
                'modified_order_amount'      => $row['ModifiedOrderAmount'],
                'store_tip_amount'           => $row['StoreTipAmount'],
                'prepaid_cash_orders'        => $row['PrepaidCashOrders'],
                'prepaid_non_cash_orders'    => $row['PrepaidNonCashOrders'],
                'prepaid_sales'              => $row['PrepaidSales'],
                'prepaid_delivery_tip'       => $row['PrepaidDeliveryTip'],
                'prepaid_in_store_tip_amount'=> $row['PrepaidInStoreTipAmount'],
                'over_short'                 => $row['OverShort'],
                'previous_day_refunds'       => $row['PreviousDayRefunds'],
                'saf'                        =>$row['SAF'],
                'manager_notes'              =>$row['ManagerNotes']
            ]);
        }
    }

    private function processSummaryTransactions($filePath)
    {
        Log::info('Processing Summary Transactions CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            SummaryTransaction::create([
                'franchise_store'    => $row['franchisestore'],
                'business_date'      => $row['businessdate'],
                'payment_method'     => $row['paymentmethod'],
                'sub_payment_method' => $row['SubPaymentMethod'],
                'total_amount'       => $row['TotalAmount'],
                'saf_qty'            => $row['SAFQty'],
                'saf_total'          => $row['SAFTotal']
            ]);
        }
    }

    private function processDetailOrders($filePath)
    {
        Log::info('Processing Detail Orders CSV file.');
        $data = $this->readCsv($filePath);
        foreach ($data as $row) {
            // Parse datetime fields
            $dateTimePlaced    = $this->parseDateTime($row['DateTimePlaced']);
            $dateTimeFulfilled = $this->parseDateTime($row['DateTimeFulfilled']);
            $promiseDate       = $this->parseDateTime($row['PromiseDate']);

            DetailOrder::create([
                'franchise_store'             => $row['FranchiseStore'],
                'business_date'               => $row['BusinessDate'],
                'date_time_placed'            => $dateTimePlaced,
                'date_time_fulfilled'         => $dateTimeFulfilled,
                'royalty_obligation'          => $row['RoyaltyObligation'],
                'quantity'                    => $row['Quantity'],
                'customer_count'              => $row['CustomerCount'],
                'order_id'                    => $row['OrderId'],
                'taxable_amount'              => $row['TaxableAmount'],
                'non_taxable_amount'          => $row['NonTaxableAmount'],
                'tax_exempt_amount'           => $row['TaxExemptAmount'],
                'non_royalty_amount'          => $row['NonRoyaltyAmount'],
                'sales_tax'                   => $row['SalesTax'],
                'employee'                    => $row['Employee'],
                'gross_sales'                 => $row['GrossSales'],
                'occupational_tax'            => $row['OccupationalTax'],
                'override_approval_employee'  => $row['OverrideApprovalEmployee'],
                'order_placed_method'         => $row['OrderPlacedMethod'],
                'delivery_tip'                => $row['DeliveryTip'],
                'delivery_tip_tax'            => $row['DeliveryTipTax'],
                'order_fulfilled_method'      => $row['OrderFulfilledMethod'],
                'delivery_fee'                => $row['DeliveryFee'],
                'modified_order_amount'       => $row['ModifiedOrderAmount'],
                'delivery_fee_tax'            => $row['DeliveryFeeTax'],
                'modification_reason'         => $row['ModificationReason'],
                'payment_methods'             => $row['PaymentMethods'],
                'delivery_service_fee'        => $row['DeliveryServiceFee'],
                'delivery_service_fee_tax'    => $row['DeliveryServiceFeeTax'],
                'refunded'                    => $row['Refunded'],
                'delivery_small_order_fee'    => $row['DeliverySmallOrderFee'],
                'delivery_small_order_fee_tax'=> $row['DeliverySmallOrderFeeTax'],
                'transaction_type'            => $row['TransactionType'],
                'store_tip_amount'            => $row['StoreTipAmount'],
                'promise_date'                => $promiseDate,
                'tax_exemption_id'            => $row['TaxExemptionId'],
                'tax_exemption_entity_name'   => $row['TaxExemptionEntityName'],
                'user_id'                     => $row['UserId'],
                'hnrOrder'                    => $row['hnrOrder'],
                'broken_promise'              => $row['Broken Promise'],
                'portal_eligible'             => $row['PortalEligible'],
                'portal_used'                 => $row['PortalUsed'],
                'put_into_portal_before_promise_time' => $row['Put into Portal before PromiseTime'],
                'portal_compartments_used'    => $row['Portal Compartments Used']
            ]);
        }
    }

    private function readCsv($filePath)
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

    private function generateNonce()
    {
        // Replicating the GetNonce() function in the Postman script
        $nonce = strtolower(bin2hex(random_bytes(16)));
        Log::info('Generated nonce: ' . $nonce);
        return $nonce;
    }

    private function prepareRequestUrlForSignature($requestUrl)
    {
        // Replace any {{variable}} in the URL if necessary
        $requestUrl = preg_replace_callback('/{{(\w*)}}/', function ($matches) {
            return env($matches[1], '');
        }, $requestUrl);

        // Encode and lowercase the URL
        $encodedUrl = strtolower(rawurlencode($requestUrl));
        Log::info('Encoded request URL: ' . $encodedUrl);
        return $encodedUrl;
    }

    private function parseDateTime($dateTimeString)
    {
        if (empty($dateTimeString)) {
            return null;
        }

        try {
            // Remove 'Z' if present
            $dateTimeString = str_replace('Z', '', $dateTimeString);

            // Parse the datetime string using Carbon
            $dateTime = Carbon::parse($dateTimeString);

            // Format to 'Y-m-d H:i:s' for MySQL
            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::error('Error parsing datetime string: ' . $dateTimeString . ' - ' . $e->getMessage());
            return null;
        }
    }

    // Optional method to delete the extracted files
    private function deleteDirectory($dirPath)
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
