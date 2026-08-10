<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use RuntimeException;

/**
 * Eine Ablage, die bei jeder Beruehrung wirft.
 *
 * Damit laesst sich pruefen, dass eine Stelle das Dateisystem **nicht** anfasst:
 * Ein Pfad hinter diesem Schema macht jedes is_file(), jedes fopen() und jedes
 * opendir() zu einer Ausnahme. Ein Test, der durchlaeuft, hat den Beweis
 * gefuehrt — ein "hat nichts geaendert" haette ihn nicht.
 */
final class ThrowingStreamWrapper
{
    public const string SCHEME = 'ablage-doppelgaenger';

    /** @var resource|null vom Stream-System gesetzt */
    public $context;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    /**
     * @return array<int|string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        throw new RuntimeException('Dateisystemzugriff auf ' . $path);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new RuntimeException('Dateisystemzugriff auf ' . $path);
    }

    public function dir_opendir(string $path, int $options): bool
    {
        throw new RuntimeException('Dateisystemzugriff auf ' . $path);
    }

    public function unlink(string $path): bool
    {
        throw new RuntimeException('Dateisystemzugriff auf ' . $path);
    }
}
