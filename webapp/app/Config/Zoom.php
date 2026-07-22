<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Kredensial + endpoint Zoom Server-to-Server OAuth (Fase 3).
 * Isi lewat .env: zoom.accountId, zoom.clientId, zoom.clientSecret, zoom.hostEmail
 * (nilai rahasia jangan di-commit - .env sudah gitignored).
 */
class Zoom extends BaseConfig
{
    public string $accountId    = '';
    public string $clientId     = '';
    public string $clientSecret = '';
    // host meeting: 'me' = pemilik kredensial, atau email user Zoom tertentu
    public string $hostEmail = 'me';

    public string $oauthURL = 'https://zoom.us/oauth/token';
    public string $apiURL   = 'https://api.zoom.us/v2';

    public int $connectTimeout = 3;
    public int $timeout        = 15;
}
