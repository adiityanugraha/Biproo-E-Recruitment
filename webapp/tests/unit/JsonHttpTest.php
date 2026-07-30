<?php

use App\Libraries\AiServiceException;
use App\Libraries\JsonHttp;
use App\Libraries\ZoomException;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kontrak JsonHttp: apa pun yang salah keluar sebagai exception milik pemanggil,
 * dengan pesan yang menyebut layanan mana yang gagal. Pesannya dikunci di sini
 * karena dipakai di log dan jadi satu-satunya petunjuk saat produksi bermasalah.
 *
 * @internal
 */
final class JsonHttpTest extends CIUnitTestCase
{
    private function http(int $status, string $body): CURLRequest
    {
        $res = $this->createMock(ResponseInterface::class);
        $res->method('getStatusCode')->willReturn($status);
        $res->method('getBody')->willReturn($body);

        $http = $this->createMock(CURLRequest::class);
        $http->method('post')->willReturn($res);

        return $http;
    }

    private function panggil(CURLRequest $http): array
    {
        return JsonHttp::request($http, 'post', 'http://contoh/x', [], 'Zoom', ZoomException::class);
    }

    public function testBody2xxDiparseJadiArray(): void
    {
        $this->assertSame(['id' => 7, 'ok' => true], $this->panggil($this->http(200, '{"id":7,"ok":true}')));
    }

    public function testStatusNon2xxJadiException(): void
    {
        $this->expectException(ZoomException::class);
        $this->expectExceptionMessage('Zoom membalas status 500');
        $this->panggil($this->http(500, '{"error":"boom"}'));
    }

    public function testBodyBukanJsonJadiException(): void
    {
        $this->expectException(ZoomException::class);
        $this->expectExceptionMessage('Zoom membalas body non-JSON');
        $this->panggil($this->http(200, '<html>gateway error</html>'));
    }

    /** Body JSON yang bukan objek/array (mis. "null", angka) juga ditolak. */
    public function testBodyJsonSkalarJugaDitolak(): void
    {
        $this->expectException(ZoomException::class);
        $this->expectExceptionMessage('Zoom membalas body non-JSON');
        $this->panggil($this->http(200, 'null'));
    }

    public function testTransportGagalJadiExceptionDanMempertahankanSebab(): void
    {
        $asli = new RuntimeException('cURL error 7: Connection refused');
        $http = $this->createMock(CURLRequest::class);
        $http->method('post')->willThrowException($asli);

        try {
            $this->panggil($http);
            $this->fail('seharusnya melempar ZoomException');
        } catch (ZoomException $e) {
            $this->assertStringContainsString('Zoom tidak dapat dihubungi', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertSame($asli, $e->getPrevious(), 'exception asli wajib dirantai untuk keperluan debug');
        }
    }

    /** Label + kelas exception ikut pemanggil, bukan dipatok di JsonHttp. */
    public function testLabelDanKelasExceptionMengikutiPemanggil(): void
    {
        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage('ai-service membalas status 503');
        JsonHttp::request($this->http(503, '{}'), 'post', 'http://contoh/x', [], 'ai-service', AiServiceException::class);
    }
}
