<?php
namespace App\Services\Helper\APIImport;

use App\Services\Helper\Logics\LogicsAndQueriesServices;
use App\Services\Helper\CSVs\ProcessCsvServices;

use GuzzleHttp\Client;
class PureIO{

    protected LogicsAndQueriesServices $logic;
    protected ProcessCsvServices $csvImporter;


    public function __construct(LogicsAndQueriesServices $logic, ProcessCsvServices $csvImporter)
    {
        $this->logic = $logic;
        $this->csvImporter = $csvImporter;
    }
    protected string $storeId = '03795';
    public function buildGetReportUrl(string $date): string
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

    public function buildHmacHeader(string $url, string $method = 'GET', string $bodyHash = ''): string
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

    public function extractZip(string $zipPath): string
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

    public function processExtractedCsv(string $extractPath, string $date): int
    {
        $data = $this->csvImporter->processCsvFiles($extractPath, $date);
        $this->logic->DataLoop($data, $date);
        return is_countable($data) ? count($data) : 0;
    }

    public function cleanupTemps(string $zipPath, string $extractPath): void
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

    private function generateNonce()
    {
        // Replicating the GetNonce() function in the Postman script
        $nonce = strtolower(string: bin2hex(random_bytes(16)));
        // Log::info('Generated nonce: ' . $nonce);
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
        //  Log::info('Encoded request URL: ' . $encodedUrl);
        return $encodedUrl;
    }
}
