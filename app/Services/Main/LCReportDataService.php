<?php

namespace App\Services\Main;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

use App\Services\Helper\APIImport\PureIO;
use App\Services\Helper\APIImport\Networked;

class LCReportDataService
{
    protected Client $client;

    protected PureIO $pureIO;
    protected Networked $networked;

    protected string $storeId = '03795';

    public function __construct(PureIO $pureIO, Networked $networked)
    {

        $this->pureIO = $pureIO;
        $this->networked = $networked;

    }

    // public function importReportData(string $selectedDate):bool
    // {

    //     $client = new Client();

    //     Log::info('Starting report data import process for date: ' . $selectedDate);

    //     // Step 1: Generate Bearer Token
    //     try {

    //         $response = $client->post(config('services.lcegateway.portal_server') . '/Token', [
    //             'form_params' => [
    //                 'grant_type' => 'password',
    //                 'UserName' => config('services.lcegateway.username'),
    //                 'Password' => config('services.lcegateway.password'),
    //             ],
    //             'headers' => [
    //                 'Accept' => 'application/json,text/plain,*/*',
    //                 'Content-Type' => 'application/x-www-form-urlencoded',
    //             ],
    //         ]);

    //         $body = json_decode($response->getBody(), true);

    //         $accessToken = $body['access_token'] ?? null;

    //         if (!$accessToken) {
    //             Log::error('Failed to obtain access token: access_token is missing in the response.');
    //             return false;
    //         }

    //     } catch (RequestException $e) {
    //         Log::error('Error obtaining access token: ' . $e->getMessage());
    //         return false;
    //     }

    //     // Step 2: Download the Report
    //     try {

    //         // Prepare variables
    //         $httpMethod = 'GET';
    //         $endpoint = '/GetReportBlobs';
    //         $userName = config('services.lcegateway.username');

    //         // Use the static storeId
    //         $storeId = '03795';

    //         // Use the selected date
    //         $fileName = $storeId . '_' . $selectedDate . '.zip';

    //         $queryParams = [
    //             'userName' => $userName,
    //             'fileName' => $fileName,
    //         ];

    //         $requestUrl = config('services.lcegateway.portal_server') . $endpoint . '?' . http_build_query($queryParams);

    //         // Build the URL for the signature
    //         $encodedRequestUrl = $this->prepareRequestUrlForSignature($requestUrl);

    //         // Generate timestamp and nonce
    //         $requestTimeStamp = time();
    //         $nonce = $this->generateNonce();

    //         // For GET requests, bodyHash is empty

    //         $bodyHash = '';

    //         // Prepare signature raw data
    //         $appId = config('services.lcegateway.hmac_user');
    //         $apiKey = config('services.lcegateway.hmac_key');
    //         $signatureRawData = $appId . $httpMethod . $encodedRequestUrl . $requestTimeStamp . $nonce . $bodyHash;

    //         // Compute HMAC SHA256
    //         $key = base64_decode($apiKey);

    //         $hash = hash_hmac('sha256', $signatureRawData, $key, true);

    //         $hashInBase64 = base64_encode($hash);


    //         // Prepare the authorization header
    //         $authHeader = 'amx ' . $appId . ':' . $hashInBase64 . ':' . $nonce . ':' . $requestTimeStamp;


    //         // Make the GET request to download the report

    //         $response = $client->get($requestUrl, [
    //             'headers' => [
    //                 'HMacAuthorizationHeader' => $authHeader,
    //                 'Content-Type' => 'application/json',
    //                 'Authorization' => 'bearer ' . $accessToken,
    //             ],
    //             'stream' => true,
    //         ]);


    //         // Determine the content type
    //         $contentType = $response->getHeaderLine('Content-Type');

    //         // Read the response body as a string
    //         $bodyString = $response->getBody()->getContents();

    //         // Decode the response body
    //         $decodedBodyOnce = json_decode($bodyString, true);

    //         if (is_string($decodedBodyOnce)) {
    //             // Decode again
    //             $decodedBody = json_decode($decodedBodyOnce, true);

    //         } else {
    //             $decodedBody = $decodedBodyOnce;
    //         }

    //         $start = microtime(true);

    //         if (isset($decodedBody[0]['ReportBlobUri'])) {
    //             $downloadUrl = $decodedBody[0]['ReportBlobUri'];

    //             $timestamp = time();
    //             $tempZipPath = storage_path('app') . DIRECTORY_SEPARATOR . "temp_report_{$timestamp}.zip";
    //             $extractPath = storage_path('app') . DIRECTORY_SEPARATOR . "temp_report_{$timestamp}";

    //             $client->get($downloadUrl, [
    //                 'sink' => $tempZipPath,
    //             ]);
    //             Log::info('Successfully downloaded the file from the provided URL.');
    //             Log::info('Download took: ' . (microtime(true) - $start) . ' seconds');

    //             $start = microtime(true);

    //             $storageAppPath = storage_path('app');
    //             if (!file_exists($storageAppPath)) {
    //                 mkdir($storageAppPath, 0775, true);

    //             }
    //             Log::info('Creating directory took: ' . (microtime(true) - $start) . ' seconds');


    //             $start = microtime(true);
    //             $zip = new \ZipArchive();
    //             if ($zip->open($tempZipPath) === true) {

    //                 $zip->extractTo($extractPath);
    //                 $zip->close();

    //                 Log::info('Extraction took: ' . (microtime(true) - $start) . ' seconds');
    //                 // Process the CSV files
    //                 $data = $this->csvImporter->processCsvFiles($extractPath, $selectedDate);
    //                 $this->logic->DataLoop($data, $selectedDate);

    //                 // Delete temporary files
    //                 unlink($tempZipPath);
    //                 // Optionally delete extracted files

    //                 $this->deleteDirectory($extractPath);

    //                 Log::info('Successfully deleted files');

    //                 return true;
    //             } else {
    //                 Log::error('Failed to open zip file.');
    //                 return false;
    //             }
    //         } else {
    //             Log::error('Failed to retrieve the report file. ReportBlobUri not found in response body.');
    //             return false;
    //         }

    //     } catch (RequestException $e) {
    //         Log::error('Error downloading report: ' . $e->getMessage());
    //         return false;
    //     }
    // }


    public function importReportData(string $selectedDate): bool
    {

        Log::info('Starting report data import process for date: ' . $selectedDate);

        $http = new Client(['timeout' => 30]);
        $zipPath     = null;
        $extractPath = null;

        try {
            $accessToken = $this->networked->fetchAccessToken($http);

            $url         = $this->pureIO->buildGetReportUrl($selectedDate);
            $hmacHeader  = $this->pureIO->buildHmacHeader($url, 'GET');
            $blobUri     = $this->networked->getReportBlobUri($http, $url, $hmacHeader, $accessToken);

            $zipPath     = $this->networked->downloadZip($http, $blobUri);
            $extractPath = $this->pureIO->extractZip($zipPath);

            $this->pureIO->processExtractedCsv($extractPath, $selectedDate);

            Log::info('Import completed successfully for date: ' . $selectedDate);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to import report data: ' . $e->getMessage());
            return false;
        } finally {
            // optional: guaranteed cleanup even on failure
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
            }
            if ($extractPath && is_dir($extractPath)) {
                $this->pureIO->deleteDirectory($extractPath);
            }
        }
    }


}
