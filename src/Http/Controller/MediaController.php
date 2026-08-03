<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\LegalDocumentRecord;
use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;
use Reptilienmarkt\Domain\Listing\ListingMediaItem;
use Reptilienmarkt\Domain\Listing\ListingMediaRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Storage\ImageException;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Infra\Storage\StorageException;

/**
 * Bild- und Nachweis-Uploads des Assistenten.
 *
 * Bilder landen als metadatenfreies WebP unter public/uploads, Nachweise
 * ausserhalb des Webroots. Beides prueft vorher die Zugehoerigkeit der Anzeige.
 */
final readonly class MediaController
{
    private const int MAX_IMAGES = 12;

    public function __construct(
        private ListingRepository $listings,
        private ListingMediaRepository $media,
        private LegalDocumentRepository $legalDocuments,
        private ImagePipeline $pipeline,
        private PublicImageStorage $images,
        private PrivateStorage $privateStorage,
        private Viewer $currentUser,
        private SessionManager $session,
    ) {}

    public function uploadImage(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listingId = $this->requireOwnListing($request, $user);
        $this->guardCsrf($request);

        $file = $request->file('bild');
        if ($file === null || !$file->isOk()) {
            return $this->back($listingId, 4, 'fehler', $file?->errorMessage() ?? 'Es wurde keine Datei ausgewählt.');
        }

        if ($this->media->countImages($listingId) >= self::MAX_IMAGES) {
            return $this->back($listingId, 4, 'fehler', \sprintf('Mehr als %d Bilder sind nicht möglich.', self::MAX_IMAGES));
        }

        $target = $this->images->allocate($listingId);

        try {
            $processed = $this->pipeline->process($file->temporaryPath, $target['absolute'], $target['thumbAbsolute']);
        } catch (ImageException $exception) {
            return $this->back($listingId, 4, 'fehler', $exception->getMessage());
        }

        $isFirst = $this->media->countImages($listingId) === 0;

        $mediaId = $this->media->add(new ListingMediaItem(
            0,
            $listingId,
            'bild',
            $target['relative'],
            $this->media->countImages($listingId),
            $isFirst,
            $processed->width,
            $processed->height,
            $processed->byteSize,
        ));

        if ($isFirst) {
            $this->media->setPrimary($listingId, $mediaId);
        }

        return $this->back($listingId, 4, 'erfolg', 'Bild hinzugefügt.');
    }

    public function deleteImage(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listingId = $this->requireOwnListing($request, $user);
        $this->guardCsrf($request);

        $mediaId = $request->attribute('media');
        if ($mediaId === null || !ctype_digit($mediaId)) {
            throw HttpException::notFound('Bild nicht gefunden.');
        }

        $item = $this->media->find((int) $mediaId);
        if ($item === null || $item->listingId !== $listingId) {
            throw HttpException::notFound('Bild nicht gefunden.');
        }

        $this->media->delete($item->id);
        $this->images->delete($item->path);

        // Ohne Titelbild waere die Anzeige in der Trefferliste unsichtbar —
        // deshalb rueckt das naechste Bild nach.
        if ($item->isPrimary) {
            $remaining = $this->media->forListing($listingId);
            if ($remaining !== []) {
                $this->media->setPrimary($listingId, $remaining[0]->id);
            }
        }

        return $this->back($listingId, 4, 'erfolg', 'Bild entfernt.');
    }

    public function setPrimaryImage(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listingId = $this->requireOwnListing($request, $user);
        $this->guardCsrf($request);

        $mediaId = $request->attribute('media');
        if ($mediaId === null || !ctype_digit($mediaId)) {
            throw HttpException::notFound('Bild nicht gefunden.');
        }

        $item = $this->media->find((int) $mediaId);
        if ($item === null || $item->listingId !== $listingId) {
            throw HttpException::notFound('Bild nicht gefunden.');
        }

        $this->media->setPrimary($listingId, $item->id);

        return $this->back($listingId, 4, 'erfolg', 'Titelbild gesetzt.');
    }

    public function uploadLegalDocument(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listingId = $this->requireOwnListing($request, $user);
        $this->guardCsrf($request);

        $typeValue = $request->body['art'] ?? '';
        $docType = \is_string($typeValue) ? LegalDocType::tryFrom($typeValue) : null;

        if ($docType === null) {
            return $this->back($listingId, 5, 'fehler', 'Unbekannte Nachweisart.');
        }

        $file = $request->file('datei');
        if ($file === null || !$file->isOk()) {
            return $this->back($listingId, 5, 'fehler', $file?->errorMessage() ?? 'Es wurde keine Datei ausgewählt.');
        }

        try {
            $stored = $this->privateStorage->store($file->temporaryPath, $file->clientFilename, 'listing-' . $listingId);
        } catch (StorageException $exception) {
            return $this->back($listingId, 5, 'fehler', $exception->getMessage());
        }

        $existing = $this->legalDocuments->findByType($listingId, $docType);

        $this->legalDocuments->save(new LegalDocumentRecord(
            $existing?->id,
            $listingId,
            $docType,
            $existing?->referenceNumber,
            $existing?->issuingAuthority,
            $existing?->issueDate,
            $stored->relativePath,
            $stored->originalFilename,
            $stored->mimeType,
            $stored->byteSize,
        ));

        // Die alte Fassung verschwindet aus der Ablage, sobald die neue steht.
        if ($existing !== null && $existing->hasFile()) {
            $this->privateStorage->delete((string) $existing->privatePath);
        }

        return $this->back($listingId, 5, 'erfolg', 'Nachweis hochgeladen.');
    }

    private function requireOwnListing(Request $request, User $user): int
    {
        $id = $request->attribute('id');
        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $listing = $this->listings->findById((int) $id);
        if ($listing === null || !$listing->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        return (int) $id;
    }

    private function back(int $listingId, int $step, string $type, string $message): Response
    {
        $this->session->flash($type, $message);

        return Response::redirect(\sprintf('/anzeige/%d/schritt/%d', $listingId, $step));
    }

    private function guardCsrf(Request $request): void
    {
        $token = $request->body['_csrf'] ?? null;

        if (!\is_string($token) || !$this->session->verifyCsrf($token)) {
            throw HttpException::badRequest('Das Formular ist abgelaufen. Bitte lade die Seite neu.');
        }
    }
}
