<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

$root = dirname(__DIR__);
$container = new Container();

$container->set('paths.root', static fn(): string => $root);
$container->set('paths.migrations', static fn(): string => $root . '/migrations');
$container->set('paths.data', static fn(): string => $root . '/data');

$container->set(Database::class, static function () use ($root): Database {
    $driver = Env::string('DB_DRIVER', 'sqlite');

    if ($driver !== 'sqlite') {
        throw new RuntimeException(sprintf(
            'DB_DRIVER "%s" wird noch nicht unterstuetzt. Umstiegsschwellen siehe docs/ARCHITEKTUR.md.',
            $driver,
        ));
    }

    $database = Env::string('DB_DATABASE', 'storage/db/reptilienmarkt.sqlite');
    if ($database !== ':memory:' && !str_starts_with($database, '/')) {
        $database = $root . '/' . $database;
    }

    return Database::sqlite($database);
});

$container->set(Migrator::class, static fn(Container $c): Migrator => new Migrator(
    $c->get(Database::class),
    $c->get('paths.migrations'),
));

$container->set(SpeciesRepository::class, static fn(Container $c): SpeciesRepository => new PdoSpeciesRepository($c->get(Database::class)));
$container->set(MorphRepository::class, static fn(Container $c): MorphRepository => new PdoMorphRepository($c->get(Database::class)));
$container->set(PostalCodeRepository::class, static fn(Container $c): PostalCodeRepository => new PdoPostalCodeRepository($c->get(Database::class)));

return $container;
