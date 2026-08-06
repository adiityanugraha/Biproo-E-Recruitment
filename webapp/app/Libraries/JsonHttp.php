<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;
use Throwable;

/**
 * Satu pintu untuk semua panggilan HTTP-JSON ke layanan luar (ai-service, Zoom).
 *
 * Kontraknya: apa pun yang salah - transport mati, timeout, status bukan 2xx,
 * body bukan JSON - keluar sebagai SATU jenis exception milik pemanggil. Jadi
 * controller cukup satu catch dan tidak pernah kebocoran exception mentah atau
 * stack trace ke user.
 *
 * Statis dan tanpa pewarisan supaya kelas layanan bebas bentuk (ZoomService
 * masih bisa di-subclass anonim untuk mock di test).
 */
final class JsonHttp
{
    /**
     * @param string               $method    method CURLRequest: 'post', 'get', ...
     * @param array<string, mixed> $options   opsi CURLRequest (json, headers, auth, timeout)
     * @param string               $label     nama layanan untuk pesan error, mis. 'Zoom'
     * @param class-string<RuntimeException> $exception kelas exception yang dilempar
     *
     * @return array<string, mixed> body yang sudah diparse
     */
    public static function request(
        CURLRequest $http,
        string $method,
        string $url,
        array $options,
        string $label,
        string $exception,
        bool $bodyWajib = true,
    ): array {
        try {
            // http_errors off: status >=400 tidak melempar, kita cek manual di bawah
            $res = $http->{$method}($url, $options + ['http_errors' => false]);
        } catch (Throwable $e) {
            // cURL error: connection refused (service mati), DNS, timeout, dll
            throw new $exception($label . ' tidak dapat dihubungi: ' . $e->getMessage(), 0, $e);
        }

        $code = $res->getStatusCode();
        if ($code < 200 || $code >= 300) {
            throw new $exception("{$label} membalas status {$code}");
        }

        $mentah = trim((string) $res->getBody());

        // 204 No Content itu jawaban yang SAH untuk penghapusan - Zoom memakainya
        // pada DELETE /meetings/{id}. Memaksa body JSON di situ membuat
        // penghapusan yang berhasil dilaporkan sebagai kegagalan.
        if (! $bodyWajib && $mentah === '') {
            return [];
        }

        $data = json_decode($mentah, true);
        if (! is_array($data)) {
            throw new $exception($label . ' membalas body non-JSON');
        }

        return $data;
    }
}
