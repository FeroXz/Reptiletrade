<?php

declare(strict_types=1);

use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\SpeciesController;
use Reptilienmarkt\Http\Routing\Router;

$router = new Router();

// Marktuebersicht. Die SEO-Pfade sind Abkuerzungen fuer Filter, die es auch
// als Query-Parameter gibt: /markt/bartagame/red-hypo/bayern/ entspricht
// /markt/?art=bartagame&morphs=red-hypo&region=bayern
$router->get('/', MarketController::class, 'home', 'startseite');
$router->get('/markt/', MarketController::class, 'search', 'markt');
$router->get('/markt/{art}/', MarketController::class, 'search', 'markt.art');
$router->get('/markt/{art}/{morphs}/', MarketController::class, 'search', 'markt.art.morphs');
$router->get('/markt/{art}/{morphs}/{region}/', MarketController::class, 'search', 'markt.art.morphs.region');

// Artenprofil als Landingpage
$router->get('/art/{slug}/', SpeciesController::class, 'show', 'art');

// REST-API auf denselben Domain-Services — Grundlage der spaeteren PWA
$router->get('/api/v1/listings', ApiController::class, 'listings', 'api.listings');
$router->get('/api/v1/arten', ApiController::class, 'species', 'api.arten');
$router->get('/api/v1/arten/{slug}/morphs', ApiController::class, 'morphs', 'api.arten.morphs');
$router->get('/api/v1/orte', ApiController::class, 'places', 'api.orte');

return $router;
