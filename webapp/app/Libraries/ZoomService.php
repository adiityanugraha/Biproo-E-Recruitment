<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\Zoom as ZoomConfig;
use Throwable;

/**
 * Client Zoom Server-to-Server OAuth (Fase 3 Day 1): ambil access token lalu
 * buat meeting terjadwal. Semua kegagalan transport / status non-2xx / body
 * invalid diseragamkan menjadi ZoomException, supaya pemanggil (aksi
 * "jadwalkan interview" recruiter) cukup satu catch dan alur utama tidak tumbang.
 */
class ZoomService
{
    private CURLRequest $http;
    private ZoomConfig $cfg;

    public function __construct(?ZoomConfig $config = null, ?CURLRequest $http = null)
    {
        $this->cfg  = $config ?? config('Zoom');
        $this->http = $http ?? service('curlrequest');
    }

    /**
     * Access token account_credentials (berlaku ~1 jam).
     *
     * ponytail: tanpa cache token - satu penjadwalan = satu-dua panggilan,
     * volume KP kecil. Tambah cache (CI4 cache, TTL ~55 menit) bila throughput naik.
     *
     * @throws ZoomException
     */
    public function token(): string
    {
        $url = $this->cfg->oauthURL . '?grant_type=account_credentials&account_id=' . rawurlencode($this->cfg->accountId);

        $data = $this->send('post', $url, [
            'auth' => [$this->cfg->clientId, $this->cfg->clientSecret], // Basic auth
        ]);

        if (empty($data['access_token'])) {
            throw new ZoomException('Zoom tidak mengembalikan access_token');
        }

        return (string) $data['access_token'];
    }

    /**
     * Buat meeting terjadwal untuk host yang dikonfigurasi.
     *
     * @param string      $topic   judul meeting (mis. "Interview - Nama - Posisi")
     * @param string|null $startAt waktu mulai ISO 8601 (mis. 2026-08-10T10:00:00), null = mulai kapan saja
     *
     * @return array{meeting_id: string, join_url: string, start_url: ?string}
     *
     * @throws ZoomException
     */
    public function createMeeting(string $topic, ?string $startAt = null): array
    {
        $token = $this->token();

        $body = ['topic' => $topic, 'type' => $startAt !== null ? 2 : 1]; // 2 = terjadwal, 1 = instan
        if ($startAt !== null) {
            $body['start_time'] = $startAt;
            $body['timezone']   = 'Asia/Jakarta';
        }

        $url  = $this->cfg->apiURL . '/users/' . rawurlencode($this->cfg->hostEmail) . '/meetings';
        $data = $this->send('post', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json'    => $body,
        ]);

        if (empty($data['id']) || empty($data['join_url'])) {
            throw new ZoomException('Zoom tidak mengembalikan id/join_url meeting');
        }

        return [
            'meeting_id' => (string) $data['id'],
            'join_url'   => (string) $data['join_url'],
            'start_url'  => isset($data['start_url']) ? (string) $data['start_url'] : null,
        ];
    }

    /**
     * Satu pintu HTTP: timeout, http_errors off (cek status manual), semua
     * kegagalan -> ZoomException.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            $res = $this->http->{$method}($url, $options + [
                'connect_timeout' => $this->cfg->connectTimeout,
                'timeout'         => $this->cfg->timeout,
                'http_errors'     => false,
            ]);
        } catch (Throwable $e) {
            throw new ZoomException('Zoom tidak dapat dihubungi: ' . $e->getMessage(), 0, $e);
        }

        $code = $res->getStatusCode();
        if ($code < 200 || $code >= 300) {
            throw new ZoomException("Zoom membalas status {$code}");
        }

        $data = json_decode((string) $res->getBody(), true);
        if (! is_array($data)) {
            throw new ZoomException('Zoom membalas body non-JSON');
        }

        return $data;
    }
}
