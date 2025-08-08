<?php

namespace App\Services\Main;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

use App\Services\Helper\LogicsAndQueriesServices;
use App\Services\Helper\CSVs\ProcessCsvServices;

class LCReportDataService
{
    protected Client $client;
    protected LogicsAndQueriesServices $logic;
    protected ProcessCsvServices $csvImporter;

    protected string $storeId = '03795';

    public function __construct(LogicsAndQueriesServices $logic, ProcessCsvServices $csvImporter)
    {
        
        $this->logic = $logic;
        $this->csvImporter = $csvImporter;
        
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
            $accessToken = $this->fetchAccessToken($http);

            $url         = $this->buildGetReportUrl($selectedDate);
            $hmacHeader  = $this->buildHmacHeader($url, 'GET');
            $blobUri     = $this->getReportBlobUri($http, $url, $hmacHeader, $accessToken);

            $zipPath     = $this->downloadZip($http, $blobUri);
            $extractPath = $this->extractZip($zipPath);

            $this->processExtractedCsv($extractPath, $selectedDate);

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
                $this->deleteDirectory($extractPath);
            }
        }
    }

    // =============== Helpers: networked ===============

    private function fetchAccessToken(Client $http): string
    {
        $url = rtrim(config('services.lcegateway.portal_server'), '/') . '/Token';

        $response = $http->post($url, [
            'form_params' => [
                'grant_type' => 'password',
                'UserName'   => config('services.lcegateway.username'),
                'Password'   => config('services.lcegateway.password'),
            ],
            'headers' => [
                'Accept'       => 'application/json,text/plain,*/*',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException("Token endpoint returned HTTP {$status}");
        }

        $body = json_decode((string) $response->getBody(), true);
        $token = $body['access_token'] ?? null;

        if (!$token) {
            throw new \RuntimeException('access_token missing in token response');
        }

        return $token;
    }

    private function getReportBlobUri(Client $http, string $url, string $hmacHeader, string $bearer): string
    {
        $resp = $http->get($url, [
            'headers' => [
                'HMacAuthorizationHeader' => $hmacHeader,
                'Authorization'           => 'bearer ' . $bearer,
                'Content-Type'            => 'application/json',
            ],
            'http_errors' => false,
            'stream'      => true,
        ]);

        $status = $resp->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException("GetReportBlobs returned HTTP {$status}");
        }

        $bodyString  = (string) $resp->getBody();
        $decodedOnce = json_decode($bodyString, true);
        $data        = is_string($decodedOnce) ? json_decode($decodedOnce, true) : $decodedOnce;

        if (empty($data[0]['ReportBlobUri'])) {
            throw new \RuntimeException('ReportBlobUri not found in response');
        }

        return $data[0]['ReportBlobUri'];
    }

    private function downloadZip(Client $http, string $downloadUrl): string
    {
        $timestamp = time();
        $zipPath   = storage_path('app') . DIRECTORY_SEPARATOR . "temp_report_{$timestamp}.zip";

        $http->get($downloadUrl, [
            'sink'        => $zipPath,
            'http_errors' => false,
        ]);

        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            throw new \RuntimeException('Downloaded ZIP is empty or missing');
        }

        return $zipPath;
    }

    // =============== Helpers: pure/IO-only ===============

    private function buildGetReportUrl(string $date): string
    {
        $base     = rtrim(config('services.lcegateway.portal_server'), '/');
        $endpoint = '/GetReportBlobs';
        $userName = config('services.lcegateway.username');
        $fileName = "{$this->storeId}_{$date}.zip";

        $query = http_build_query([
            'userName' => $userName,
            'fileName' => $fileName,
        ]);

        return "{$base}{$endpoint}?{$query}";
    }

    private function buildHmacHeader(string $url, string $method = 'GET', string $bodyHash = ''): string
    {
        $appId   = config('services.lcegateway.hmac_user');
        $apiKey  = config('services.lcegateway.hmac_key');

        $requestTimeStamp  = time();
        $nonce             = $this->generateNonce();
        $encodedRequestUrl = $this->prepareRequestUrlForSignature($url);

        $signatureRawData = $appId
            . strtoupper($method)
            . $encodedRequestUrl
            . $requestTimeStamp
            . $nonce
            . $bodyHash;

        $key          = base64_decode($apiKey);
        $hash         = hash_hmac('sha256', $signatureRawData, $key, true);
        $hashInBase64 = base64_encode($hash);

        return 'amx ' . $appId . ':' . $hashInBase64 . ':' . $nonce . ':' . $requestTimeStamp;
    }

    private function extractZip(string $zipPath): string
    {
        $extractPath = preg_replace('/\.zip$/i', '', $zipPath) ?: ($zipPath . '_extracted');

        if (!is_dir($extractPath) && !mkdir($extractPath, 0775, true) && !is_dir($extractPath)) {
            throw new \RuntimeException("Failed to create extract dir: {$extractPath}");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open zip file.');
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new \RuntimeException('Failed to extract zip file.');
        }

        $zip->close();
        return $extractPath;
    }

    private function processExtractedCsv(string $extractPath, string $date): int
    {
        $data = $this->csvImporter->processCsvFiles($extractPath, $date);
        $this->logic->DataLoop($data, $date);
        return is_countable($data) ? count($data) : 0;
    }

    private function cleanupTemps(string $zipPath, string $extractPath): void
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
        if (is_dir($extractPath)) {
            $this->deleteDirectory($extractPath);
        }
    }

    //delete files
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

}
