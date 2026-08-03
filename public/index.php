<?php

declare(strict_types=1);

use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Support\Container;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

/** @var Kernel $kernel */
$kernel = $container->get(Kernel::class);

$kernel->handle(Request::fromGlobals())->send();
