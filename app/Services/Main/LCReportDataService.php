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
