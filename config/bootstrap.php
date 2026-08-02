<?php

declare(strict_types=1);

use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');

date_default_timezone_set(Env::string('APP_TIMEZONE', 'Europe/Berlin'));

if (Env::bool('APP_DEBUG')) {
    error_reporting(\E_ALL);
    ini_set('display_errors', '1');
}

/** @var Container $container */
$container = require $root . '/config/container.php';

return $container;
