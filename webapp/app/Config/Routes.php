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
    $routes->get('nilai/(:num)', 'Recruiter::formNilai/$1');
    $routes->post('interview/putus/(:num)', 'Recruiter::putusInterview/$1');
    // keputusan Gate 2 manual, dipakai saat skor CV tidak tersedia
    $routes->post('gate2/(:num)', 'Recruiter::putusGate2/$1');
    $routes->match(['GET', 'POST'], 'review/(:num)', 'Recruiter::review/$1');
    $routes->match(['GET', 'POST'], 'pertanyaan/(:num)', 'Recruiter::pertanyaan/$1');
});
