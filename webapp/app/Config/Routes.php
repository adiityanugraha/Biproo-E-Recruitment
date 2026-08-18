<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/login'));

$routes->match(['GET', 'POST'], 'daftar', 'Auth::daftar');
$routes->match(['GET', 'POST'], 'login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

// jalur internal ai-service (tanpa sesi; dijaga token X-Token, lihat Screening.php)
$routes->get('internal/cv/(:num)', 'Screening::cvFile/$1');
$routes->post('screening/callback', 'Screening::callback');
$routes->get('internal/rekaman/(:num)', 'Interview::rekamanFile/$1');
$routes->post('interview/callback', 'Interview::callback');

$routes->group('', ['filter' => 'candidateauth'], static function ($routes) {
    $routes->get('dashboard', 'Lamaran::dashboard');
    $routes->get('lamar', 'Lamaran::index');
    $routes->post('lamar', 'Lamaran::kirim');
    $routes->get('status', 'Lamaran::status');
    $routes->get('jadwal', 'Lamaran::jadwalInterview');
    $routes->get('assessment/(:num)', 'Lamaran::assessment/$1');
    $routes->post('assessment/(:num)', 'Lamaran::jawabAssessment/$1');
    $routes->post('interview/ajukan/(:num)', 'Lamaran::ajukanInterview/$1');
    $routes->get('interview/masuk/(:num)', 'Lamaran::masukInterview/$1');
    $routes->post('chat/ask', 'Chat::ask');
});

$routes->match(['GET', 'POST'], 'recruiter/login', 'Recruiter::login');
$routes->get('recruiter/logout', 'Recruiter::logout');
$routes->group('recruiter', ['filter' => 'recruiterauth'], static function ($routes) {
    $routes->get('', 'Recruiter::index');
    $routes->get('tahap/(:segment)', 'Recruiter::tahap/$1');
    $routes->get('kandidat', 'Recruiter::kandidat');
    $routes->get('cv/(:num)', 'Recruiter::cvKandidat/$1');
    $routes->post('interview/reschedule/(:num)', 'Recruiter::rescheduleInterview/$1');
    // ruang interview per kandidat: tautan Zoom, tiga pertanyaan, unggah rekaman
    $routes->get('ruang/(:num)', 'Recruiter::ruangInterview/$1');
    $routes->post('ruang/(:num)/pertanyaan', 'Recruiter::simpanPertanyaan/$1');
    $routes->post('ruang/(:num)/rekaman', 'Recruiter::unggahRekaman/$1');
    // lembar profil kandidat 3 halaman, dibuka di tab baru untuk dicetak
    $routes->get('profil/(:num)', 'Recruiter::profil/$1');
    // Keputusan Gate 2 manual - satu-satunya jalan keluar manusia sejak Gate 2
    // menutup sendiri dari transkrip. Dipakai saat datanya memang kurang.
    $routes->post('gate2/(:num)', 'Recruiter::putusGate2/$1');
    // Settings: alur rekrutmen per posisi (18 Agustus 2026). Tiap lowongan
    // punya rangkaian tahapnya sendiri, mengikuti web recruiter BIPROO.
    $routes->get('pengaturan', 'Recruiter::pengaturan');
    $routes->match(['GET', 'POST'], 'pengaturan/alur/(:num)', 'Recruiter::alurLowongan/$1');
    $routes->match(['GET', 'POST'], 'review/(:num)', 'Recruiter::review/$1');
    $routes->match(['GET', 'POST'], 'pertanyaan/(:num)', 'Recruiter::pertanyaan/$1');
});
