<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\AiService as AiServiceConfig;

/**
 * Client tahan-gagal ke microservice AI (FastAPI). Semua kegagalan transport -
 * service mati, timeout, HTTP bukan 2xx, body non-JSON - diseragamkan menjadi
 * AiServiceException, supaya pemanggil (chatbot dsb) cukup satu catch lalu
 * memberi pesan ramah ke user, bukan membocorkan exception mentah / stack trace.
 */
class AiService
{
    private CURLRequest $http;
    private string $baseURL;
    private int $connectTimeout;
    private int $timeout;

    public function __construct(?AiServiceConfig $config = null, ?CURLRequest $http = null)
    {
        $config               = $config ?? config('AiService');
        $this->baseURL        = rtrim($config->baseURL, '/');
        $this->connectTimeout = $config->connectTimeout;
        $this->timeout        = $config->timeout;
        $this->http           = $http ?? service('curlrequest');
    }

    /**
     * POST JSON ke ai-service, kembalikan body yang sudah diparse.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws AiServiceException bila service tak terjangkau / status bukan 2xx / body invalid
     */
    public function post(string $path, array $payload): array
    {
        return JsonHttp::request($this->http, 'post', $this->baseURL . '/' . ltrim($path, '/'), [
            'json'            => $payload,
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
        ], 'ai-service', AiServiceException::class);
    }
}
