<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Debug\ExceptionHandler;
use PHPUnit\Framework\TestCase;

/**
 * Routing exception handler: hanya kegagalan koneksi DB yang dapat halaman
 * ramah; error query & error lain tetap ke handler default.
 *
 * @internal
 */
final class ExceptionsHandlerTest extends TestCase
{
    private function handlerFor(Throwable $e): object
    {
        return (new Config\Exceptions())->handler(500, $e);
    }

    public function testKoneksiDbGagalDapatHandlerRamah(): void
    {
        $h = $this->handlerFor(new DatabaseException('Unable to connect to the database.'));
        $this->assertInstanceOf(App\Libraries\ServiceDownHandler::class, $h);
    }

    public function testErrorQueryTetapHandlerDefault(): void
    {
        // DatabaseException tapi bukan kegagalan koneksi -> jangan tersamar
        $h = $this->handlerFor(new DatabaseException('Syntax error in query'));
        $this->assertInstanceOf(ExceptionHandler::class, $h);
    }

    public function testErrorLainTetapHandlerDefault(): void
    {
        $h = $this->handlerFor(new RuntimeException('boom'));
        $this->assertInstanceOf(ExceptionHandler::class, $h);
    }
}
