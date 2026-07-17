<?php

use App\Libraries\AiService;
use App\Libraries\AiServiceException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\AiService as AiServiceConfig;

/**
 * Client ai-service: kegagalan transport harus jadi AiServiceException,
 * bukan exception mentah yang bocor ke pemanggil.
 *
 * @internal
 */
final class AiServiceTest extends CIUnitTestCase
{
    public function testServiceMatiMenjadiAiServiceException(): void
    {
        $config                 = new AiServiceConfig();
        $config->baseURL        = 'http://127.0.0.1:59999'; // port mati -> connection refused
        $config->connectTimeout = 2;
        $config->timeout        = 2;

        $this->expectException(AiServiceException::class);
        (new AiService($config))->post('/chat', ['question' => 'halo']);
    }
}
