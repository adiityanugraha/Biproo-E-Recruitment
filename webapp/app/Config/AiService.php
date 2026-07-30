<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi microservice AI (FastAPI, Blueprint A3).
 * Override lewat .env: aiservice.baseURL, aiservice.connectTimeout, aiservice.timeout
 */
class AiService extends BaseConfig
{
    public string $baseURL = 'http://localhost:8000';

    // connect cepat supaya service mati terdeteksi segera; timeout total lebih
    // longgar karena respons LLM/embedding bisa lambat
    public int $connectTimeout = 3;
    public int $timeout        = 20;

    // Token bersama untuk jalur internal (unduh CV + callback screening).
    // Kosong = jalur internal MATI (fail-closed). Isi via .env: aiservice.sharedToken
    public string $sharedToken = '';
}
