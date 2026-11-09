<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Rutas API REST
$routes->group('api', static function ($routes) {
    // Leads / Prospectos
    $routes->get('leads/stats', 'Api\Leads::stats');
    $routes->get('leads/export', 'Api\Leads::export');
    $routes->get('leads', 'Api\Leads::index');
    $routes->get('leads/(:num)', 'Api\Leads::show/$1');
    $routes->match(['post', 'patch'], 'leads/(:num)', 'Api\Leads::update/$1');
    $routes->delete('leads/(:num)', 'Api\Leads::delete/$1');

    // Jobs / Tareas de Scraping
    $routes->get('jobs', 'Api\Jobs::index');
    $routes->post('jobs', 'Api\Jobs::create');
    $routes->get('jobs/(:num)/status', 'Api\Jobs::status/$1');
});
