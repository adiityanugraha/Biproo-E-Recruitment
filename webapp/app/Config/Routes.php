<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/login'));

$routes->match(['GET', 'POST'], 'daftar', 'Auth::daftar');
$routes->match(['GET', 'POST'], 'login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

$routes->group('', ['filter' => 'candidateauth'], static function ($routes) {
    $routes->get('lamar', 'Lamaran::index');
    $routes->post('lamar', 'Lamaran::kirim');
    $routes->get('status', 'Lamaran::status');
    $routes->get('assessment/(:num)', 'Lamaran::assessment/$1');
    $routes->post('assessment/(:num)', 'Lamaran::jawabAssessment/$1');
});
