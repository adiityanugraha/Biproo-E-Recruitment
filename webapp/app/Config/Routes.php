<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/login'));

$routes->match(['GET', 'POST'], 'daftar', 'Auth::daftar');
$routes->match(['GET', 'POST'], 'login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

$routes->group('', ['filter' => 'candidateauth'], static function ($routes) {
    $routes->get('dashboard', 'Lamaran::dashboard');
    $routes->get('lamar', 'Lamaran::index');
    $routes->post('lamar', 'Lamaran::kirim');
    $routes->get('status', 'Lamaran::status');
    $routes->get('assessment/(:num)', 'Lamaran::assessment/$1');
    $routes->post('assessment/(:num)', 'Lamaran::jawabAssessment/$1');
    $routes->post('chat/ask', 'Chat::ask');
});

$routes->match(['GET', 'POST'], 'recruiter/login', 'Recruiter::login');
$routes->get('recruiter/logout', 'Recruiter::logout');
$routes->group('recruiter', ['filter' => 'recruiterauth'], static function ($routes) {
    $routes->get('', 'Recruiter::index');
    $routes->get('report', 'Recruiter::report');
    $routes->get('tahap/(:segment)', 'Recruiter::tahap/$1');
    $routes->get('kandidat/(:num)', 'Recruiter::kandidat/$1');
    $routes->match(['GET', 'POST'], 'review/(:num)', 'Recruiter::review/$1');
});
