<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Storage\PrivateStorage;

/**
 * Auslieferung der Rechtsnachweise.
 *
 * Es gibt keinen Webserver-Pfad auf storage/private. Jeder Abruf laeuft
 * hierdurch: erst Anmeldung, dann Berechtigung, dann readfile(). Sehen darf
 * ein Dokument nur, wer die Anzeige angelegt hat — oder die Moderation.
 */
final readonly class LegalDocumentController
{
    public function __construct(
        private LegalDocumentRepository $documents,
        private ListingRepository $listings,
        private Viewer $currentUser,
        private PrivateStorage $storage,
    ) {}

    public function download(Request $request): Response
    {
        // Wirft NotAuthenticatedException, wenn niemand angemeldet ist — der
        // Kernel macht daraus eine Weiterleitung bzw. eine 401 fuer die API.
        $user = $this->currentUser->require();

        $id = $request->attribute('id');
        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Nachweis nicht gefunden.');
        }

        $document = $this->documents->find((int) $id);
        if ($document === null || !$document->hasFile()) {
            throw HttpException::notFound('Nachweis nicht gefunden.');
        }

        $listing = $this->listings->findById($document->listingId);
        if ($listing === null) {
            throw HttpException::notFound('Nachweis nicht gefunden.');
        }

        $darfSehen = $listing->belongsTo($user->id ?? 0) || $user->role->mayModerate();
        if (!$darfSehen) {
            // Bewusst 404 statt 403: Ein 403 wuerde bestaetigen, dass es die
            // Datei gibt.
            throw HttpException::notFound('Nachweis nicht gefunden.');
        }

        $path = (string) $document->privatePath;
        if (!$this->storage->exists($path)) {
            throw HttpException::notFound('Die Datei ist nicht mehr vorhanden.');
        }

        $contents = file_get_contents($this->storage->absolutePath($path));
        if ($contents === false) {
            throw HttpException::notFound('Die Datei konnte nicht gelesen werden.');
        }

        $filename = $document->originalFilename ?? ('nachweis-' . $document->id);

        return new Response($contents, 200, [
            'content-type' => $document->mimeType ?? 'application/octet-stream',
            'content-length' => (string) \strlen($contents),
            // Immer als Anhang: Ein im Browser gerendertes Dokument koennte
            // aktive Inhalte mitbringen.
            'content-disposition' => 'attachment; filename="' . $this->safeFilename($filename) . '"',
            'cache-control' => 'private, no-store',
            'x-robots-tag' => 'noindex, nofollow',
        ]);
    }

    private function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'nachweis';

        return trim($safe, '_') === '' ? 'nachweis' : $safe;
    }
}
