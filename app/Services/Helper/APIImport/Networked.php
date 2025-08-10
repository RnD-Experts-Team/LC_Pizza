<?php
namespace App\Services\Helper\APIImport;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
class Networked{
    public function fetchAccessToken(Client $http): string
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
            'timeout'         => 60,
            'connect_timeout' => 15,
            'http_errors'     => false,
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

    public function getReportBlobUri(Client $http, string $url, string $hmacHeader, string $bearer): string
    {
        $resp = $http->get($url, [
            'headers' => [
                'HMacAuthorizationHeader' => $hmacHeader,
                'Authorization'           => 'bearer ' . $bearer,
                'Content-Type'            => 'application/json',
            ],
            'http_errors' => false,
            'timeout'         => 90,
            'connect_timeout' => 15,
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

    public function downloadZip(Client $http, string $downloadUrl): string
    {
        $timestamp = time();
        $zipPath   = storage_path('app') . DIRECTORY_SEPARATOR . "temp_report_{$timestamp}.zip";

        $http->get($downloadUrl, [
            'sink'        => $zipPath,
            'timeout'         => 600, // 10 minutes
            'connect_timeout' => 20,
            'http_errors' => false,
        ]);

        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            throw new \RuntimeException('Downloaded ZIP is empty or missing');
        }

        return $zipPath;
    }
}
