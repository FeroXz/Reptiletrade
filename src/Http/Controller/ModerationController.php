<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Message\MessageRepository;
use Reptilienmarkt\Domain\Moderation\ReportException;
use Reptilienmarkt\Domain\Moderation\ReportRepository;
use Reptilienmarkt\Domain\Moderation\ReportService;
use Reptilienmarkt\Domain\Moderation\ReportStatus;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserDocument;
use Reptilienmarkt\Domain\User\UserDocumentRepository;
use Reptilienmarkt\Domain\User\UserDocumentStatus;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Clock;
use Twig\Environment;

/**
 * Die Moderationsliste: gemeldete Inhalte, markierte Nachrichten, zu pruefende
 * Anzeigen und Identitaetsnachweise.
 *
 * Das vollstaendige Admin-Dashboard kommt in Phase 7; hier steht nur, was die
 * in dieser Phase gebauten Meldewege brauchen, um nicht ins Leere zu laufen.
 */
final readonly class ModerationController
{
    public function __construct(
        private ReportService $reports,
        private ReportRepository $reportRepository,
        private MessageRepository $messages,
        private ListingRepository $listings,
        private UserDocumentRepository $documents,
        private VerificationRepository $verification,
        private ListingIndexer $indexer,
        private AuditLog $audit,
        private Viewer $currentUser,
        private SessionManager $session,
        private Clock $clock,
        private Environment $twig,
    ) {}

    public function queue(Request $request): Response
    {
        $this->requireModerator();

        return Response::html($this->twig->render('moderation/liste.html.twig', [
            'meldungen_offen' => $this->reports->queue(),
            'markierte_nachrichten' => $this->messages->flagged(25),
            'anzeigen_in_pruefung' => $this->listings->inStatus(ListingStatus::Pruefung, 25),
            'nachweise' => $this->documents->pending(25),
            'offen_gesamt' => $this->reports->openCount(),
            'csrf' => $this->session->csrfToken(),
            'flash' => $this->session->takeFlashes(),
        ]));
    }

    public function resolveReport(Request $request): Response
    {
        $moderator = $this->requireModerator();
        $this->guardCsrf($request);

        $report = $this->reportRepository->findById($this->requireId($request));
        $status = ReportStatus::tryFrom($this->input($request, 'status'));

        if ($report === null || $status === null) {
            throw HttpException::notFound('Meldung nicht gefunden.');
        }

        try {
            $this->reports->resolve($report, $moderator, $status, $this->nullable($request, 'notiz'));
            $this->session->flash('erfolg', 'Meldung bearbeitet.');
        } catch (ReportException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/moderation/');
    }

    /**
     * Gibt eine gepruefte Anzeige frei oder sperrt sie.
     */
    public function decideListing(Request $request): Response
    {
        $moderator = $this->requireModerator();
        $this->guardCsrf($request);

        $listing = $this->listings->findById($this->requireId($request));
        if ($listing === null) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $freigeben = $this->input($request, 'entscheidung') === 'freigeben';
        $status = $freigeben ? ListingStatus::Aktiv : ListingStatus::Gesperrt;

        $this->listings->updateStatus($listing->id ?? 0, $status);

        // Eine gesperrte Anzeige darf nicht in der Suche stehen bleiben.
        if ($freigeben) {
            $this->indexer->indexListing($listing->id ?? 0);
        } else {
            $this->indexer->removeListing($listing->id ?? 0);
        }

        $this->audit->record(new AuditEntry(
            $freigeben ? 'listing.approved' : 'listing.blocked',
            'listing',
            $listing->id,
            ['notiz' => $this->nullable($request, 'notiz')],
            $moderator->id,
            AuditActorType::Admin,
        ));

        $this->session->flash('erfolg', $freigeben ? 'Anzeige freigegeben.' : 'Anzeige gesperrt.');

        return Response::redirect('/moderation/');
    }

    /**
     * Entscheidet ueber einen Identitaets- oder Gewerbenachweis.
     */
    public function decideDocument(Request $request): Response
    {
        $moderator = $this->requireModerator();
        $this->guardCsrf($request);

        $document = $this->documents->findById($this->requireId($request));
        if ($document === null) {
            throw HttpException::notFound('Nachweis nicht gefunden.');
        }

        $angenommen = $this->input($request, 'entscheidung') === 'annehmen';
        $now = $this->clock->now();

        $this->documents->save(new UserDocument(
            $document->id,
            $document->userId,
            $document->docType,
            $document->privatePath,
            $document->originalFilename,
            $document->mimeType,
            $document->byteSize,
            $angenommen ? UserDocumentStatus::Geprueft : UserDocumentStatus::Abgelehnt,
            $moderator->id,
            $now,
            $this->nullable($request, 'notiz'),
            $document->createdAt,
        ));

        if ($angenommen) {
            $this->verification->markIdentityVerified($document->userId, $now);
        }

        $this->audit->record(new AuditEntry(
            $angenommen ? 'user.identity_verified' : 'user.identity_rejected',
            'user',
            $document->userId,
            ['nachweis' => $document->docType->value],
            $moderator->id,
            AuditActorType::Admin,
        ));

        $this->session->flash('erfolg', $angenommen ? 'Nachweis angenommen.' : 'Nachweis abgelehnt.');

        return Response::redirect('/moderation/');
    }

    private function requireModerator(): User
    {
        $user = $this->currentUser->require();

        if (!$user->isModerator()) {
            // 404 statt 403: Die Moderationsoberflaeche muss sich nicht dadurch
            // verraten, dass sie einen Zugriff ablehnt.
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }

    private function requireId(Request $request): int
    {
        $value = $request->attribute('id');

        if ($value === null || !ctype_digit($value)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return (int) $value;
    }

    private function nullable(Request $request, string $name): ?string
    {
        $value = $this->input($request, $name);

        return $value === '' ? null : $value;
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function guardCsrf(Request $request): void
    {
        $token = $request->body['_csrf'] ?? null;

        if (!\is_string($token) || !$this->session->verifyCsrf($token)) {
            throw HttpException::badRequest('Das Formular ist abgelaufen. Bitte lade die Seite neu.');
        }
    }
}
