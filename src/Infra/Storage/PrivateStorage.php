<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

use RuntimeException;

/**
 * Ablage fuer Rechtsnachweise.
 *
 * Das Verzeichnis liegt ausserhalb des Webroots. Es gibt keine URL, die auf
 * eine dieser Dateien zeigt — die Auslieferung laeuft ausschliesslich ueber
 * einen Controller, der vorher die Berechtigung prueft und dann readfile()
 * aufruft.
 *
 * Der gespeicherte Pfad ist immer relativ und wird bei jedem Zugriff erneut
 * geprueft: Ein aus der Datenbank gelesener Pfad wird wie eine Nutzereingabe
 * behandelt.
 */
final readonly class PrivateStorage
{
    /** @var list<string> */
    private const array ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const int MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private string $basePath) {}

    /**
     * Legt eine hochgeladene Datei ab und liefert den relativen Pfad.
     *
     * @throws StorageException
     */
    public function store(string $sourcePath, string $originalFilename, ?string $subdirectory = null): StoredFile
    {
        if (!is_file($sourcePath)) {
            throw new StorageException('Die hochgeladene Datei wurde nicht gefunden.');
        }

        $size = filesize($sourcePath);
        if ($size === false || $size === 0) {
            throw new StorageException('Die hochgeladene Datei ist leer.');
        }

        if ($size > self::MAX_BYTES) {
            throw new StorageException(\sprintf('Die Datei ist größer als %d MB.', intdiv(self::MAX_BYTES, 1024 * 1024)));
        }

        $mime = $this->detectMime($sourcePath);
        if (!\in_array($mime, self::ALLOWED_MIME, true)) {
            throw new StorageException('Erlaubt sind PDF, JPEG, PNG und WebP.');
        }

        $relative = \sprintf(
            '%s/%s/%s.%s',
            $subdirectory === null ? 'nachweise' : $this->safeSegment($subdirectory),
            gmdate('Y/m'),
            bin2hex(random_bytes(16)),
            $this->extensionFor($mime),
        );

        $absolute = $this->absolutePath($relative);
        $directory = \dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Verzeichnis konnte nicht angelegt werden: %s', $directory));
        }

        if (!@rename($sourcePath, $absolute) && !@copy($sourcePath, $absolute)) {
            throw new StorageException('Die Datei konnte nicht gespeichert werden.');
        }

        chmod($absolute, 0o640);

        return new StoredFile($relative, $originalFilename, $mime, $size);
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolutePath($relativePath));
    }

    /**
     * Absoluter Pfad — nur nach erfolgreicher Pruefung.
     *
     * @throws StorageException wenn der Pfad aus dem Ablageverzeichnis herausfuehrt
     */
    public function absolutePath(string $relativePath): string
    {
        $this->guardRelativePath($relativePath);

        return $this->basePath . '/' . $relativePath;
    }

    public function delete(string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);

        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    /**
     * @throws StorageException
     */
    private function guardRelativePath(string $relativePath): void
    {
        if ($relativePath === '') {
            throw new StorageException('Leerer Pfad.');
        }

        if (str_starts_with($relativePath, '/') || preg_match('#^[a-zA-Z]:#', $relativePath) === 1) {
            throw new StorageException('Absolute Pfade sind nicht zulässig.');
        }

        // Auch kodierte und Backslash-Varianten abfangen, bevor irgendetwas
        // am Dateisystem passiert.
        $normalized = str_replace('\\', '/', rawurldecode($relativePath));

        if (str_contains($normalized, '..') || str_contains($normalized, "\0")) {
            throw new StorageException('Ungültiger Pfad.');
        }

        if (preg_match('#^[A-Za-z0-9._/-]+$#', $relativePath) !== 1) {
            throw new StorageException('Ungültige Zeichen im Pfad.');
        }
    }

    private function safeSegment(string $segment): string
    {
        $safe = (string) preg_replace('#[^A-Za-z0-9_-]+#', '-', $segment);

        return trim($safe, '-') ?: 'nachweise';
    }

    private function detectMime(string $path): string
    {
        if (\function_exists('finfo_open')) {
            $finfo = finfo_open(\FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (\is_string($mime)) {
                    return $mime;
                }
            }
        }

        $info = @getimagesize($path);

        return \is_array($info) ? $info['mime'] : 'application/octet-stream';
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
