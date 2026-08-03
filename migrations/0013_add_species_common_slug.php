<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Zweiter Slug aus dem deutschen Namen. Die Marktpfade sprechen die
        // Nutzersprache (/markt/bartagame/), die Artenprofile bleiben
        // wissenschaftlich (/art/pogona-vitticeps/).
        $pdo->exec('ALTER TABLE species ADD COLUMN common_slug TEXT');

        $pdo->exec('CREATE UNIQUE INDEX uq_species_common_slug ON species(common_slug) WHERE common_slug IS NOT NULL');

        // Altersfilter der Suche vergleicht hatch_date gegen zwei Datumsgrenzen.
        $pdo->exec("CREATE INDEX idx_listings_status_hatch ON listings(status, hatch_date) WHERE hatch_date IS NOT NULL");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_listings_status_hatch');
        $pdo->exec('DROP INDEX IF EXISTS uq_species_common_slug');
        $pdo->exec('ALTER TABLE species DROP COLUMN common_slug');
    }
};
