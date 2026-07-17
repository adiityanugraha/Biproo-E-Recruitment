<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\AiService as AiServiceConfig;
use Throwable;

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
        try {
            $res = $this->http->post($this->baseURL . '/' . ltrim($path, '/'), [
                'json'            => $payload,
                'connect_timeout' => $this->connectTimeout,
                'timeout'         => $this->timeout,
                'http_errors'     => false, // status >=400 tidak melempar; kita cek manual di bawah
            ]);
        } catch (Throwable $e) {
            // cURL error: connection refused (service mati), DNS, timeout, dll
            throw new AiServiceException('ai-service tidak dapat dihubungi: ' . $e->getMessage(), 0, $e);
        }

        $code = $res->getStatusCode();
        if ($code < 200 || $code >= 300) {
            throw new AiServiceException("ai-service membalas status {$code}");
        }

        $data = json_decode((string) $res->getBody(), true);
        if (! is_array($data)) {
            throw new AiServiceException('ai-service membalas body non-JSON');
        }

        return $data;
    }
}
