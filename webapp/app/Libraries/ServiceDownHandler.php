<?php

namespace App\Libraries;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Halaman ramah saat database tak terjangkau (mis. SQL Server mati),
 * menggantikan halaman error CI4 generik / stack trace. Dipilih di
 * Config\Exceptions::handler() hanya untuk kegagalan KONEKSI.
 */
class ServiceDownHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        log_message('critical', 'Database tak terjangkau: {msg}', ['msg' => $exception->getMessage()]);

        $response->setStatusCode(503)
            ->setBody(view('errors/html/error_db'))
            ->send();

        exit($exitCode);
    }
}
