<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Moderation\ReportException;
use Reptilienmarkt\Domain\Moderation\ReportReason;
use Reptilienmarkt\Domain\Moderation\ReportService;
use Reptilienmarkt\Domain\Moderation\ReportTargetType;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Der Meldebutton an Anzeigen, Profilen und Nachrichten.
 */
final readonly class ReportController
{
    public function __construct(
        private ReportService $reports,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function form(Request $request): Response
    {
        $this->currentUser->require();

        [$type, $targetId] = $this->target($request);

        return Response::html($this->twig->render('meldung/formular.html.twig', [
            'ziel_art' => $type,
            'ziel_id' => $targetId,
            'gruende' => ReportReason::cases(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function submit(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        [$type, $targetId] = $this->target($request);

        $reason = ReportReason::tryFrom($this->input($request, 'grund'));
        if ($reason === null) {
            $this->session->flash('fehler', 'Bitte wähle einen Grund aus.');

            return Response::redirect(\sprintf('/melden/%s/%d', $type->value, $targetId));
        }

        try {
            $this->reports->report(
                $user,
                $type,
                $targetId,
                $reason,
                $this->input($request, 'beschreibung'),
                $request->clientIp,
            );

            $this->session->flash('erfolg', $this->translator->translate('meldung.gesendet'));
        } catch (ReportException|RateLimitExceededException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect($this->backTo($type, $targetId));
    }

    /**
     * @return array{ReportTargetType, int}
     */
    private function target(Request $request): array
    {
        $type = ReportTargetType::tryFrom($request->attribute('art') ?? '');
        $id = $request->attribute('id');

        if ($type === null || $id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return [$type, (int) $id];
    }

    private function backTo(ReportTargetType $type, int $targetId): string
    {
        return match ($type) {
            ReportTargetType::Listing => \sprintf('/anzeige/%d/', $targetId),
            ReportTargetType::Message => '/postfach/',
            ReportTargetType::User => '/markt/',
        };
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
