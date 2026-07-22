<?php

use App\Libraries\ZoomException;
use App\Libraries\ZoomService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Zoom as ZoomConfig;

/**
 * ZoomService: kegagalan transport harus jadi ZoomException, bukan exception
 * mentah. Happy-path (buat meeting nyata) diuji manual setelah kredensial ada.
 *
 * @internal
 */
final class ZoomServiceTest extends CIUnitTestCase
{
    private function deadConfig(): ZoomConfig
    {
        $cfg                 = new ZoomConfig();
        $cfg->oauthURL       = 'http://127.0.0.1:59999/oauth/token'; // port mati -> connection refused
        $cfg->accountId      = 'x';
        $cfg->clientId       = 'x';
        $cfg->clientSecret   = 'x';
        $cfg->connectTimeout = 1;
        $cfg->timeout        = 1;

        return $cfg;
    }

    public function testTokenSaatZoomMatiJadiZoomException(): void
    {
        $this->expectException(ZoomException::class);
        (new ZoomService($this->deadConfig()))->token();
    }

    public function testCreateMeetingSaatZoomMatiJadiZoomException(): void
    {
        // createMeeting memanggil token() dulu -> tetap ZoomException, bukan error mentah
        $this->expectException(ZoomException::class);
        (new ZoomService($this->deadConfig()))->createMeeting('Interview - Budi - Backend', '2026-08-10T10:00:00');
    }
}
