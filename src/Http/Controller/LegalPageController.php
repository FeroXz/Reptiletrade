<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Legal\Disclaimer;
use Twig\Environment;

/**
 * Impressum, Datenschutzerklaerung, Nutzungsbedingungen.
 *
 * Der Inhalt kommt aus config/impressum.php, nicht aus dem Code: Was dort
 * steht, ist die Sache des Betreibers und aendert sich, ohne dass jemand ein
 * Template anfasst. Fehlen Pflichtangaben, sagt die Seite das sichtbar —
 * ein Impressum mit Platzhaltern sieht sonst aus wie ein fertiges.
 */
final readonly class LegalPageController
{
    public function __construct(
        private SiteIdentity $identity,
        private Environment $twig,
        private string $appUrl,
    ) {}

    public function imprint(Request $request): Response
    {
        return $this->page('recht/impressum.html.twig');
    }

    public function privacy(Request $request): Response
    {
        return $this->page('recht/datenschutz.html.twig');
    }

    public function terms(Request $request): Response
    {
        return $this->page('recht/nutzungsbedingungen.html.twig');
    }

    private function page(string $template): Response
    {
        return Response::html($this->twig->render($template, [
            'anbieter' => $this->identity->provider(),
            'anschrift' => $this->identity->addressLines(),
            'kontakt' => $this->identity->contact(),
            'register' => $this->identity->register(),
            'datenschutz' => $this->identity->privacy(),
            'hosting' => $this->identity->hosting(),
            'ust_id' => $this->identity->value('umsatzsteuer_id'),
            'verantwortlich' => $this->identity->value('inhaltlich_verantwortlich'),
            'aufsicht' => $this->identity->value('aufsichtsbehoerde'),
            'streit_bereit' => $this->identity->readyForDisputeResolution(),
            'streit_stelle' => $this->identity->disputeBody(),
            'fehlend' => $this->identity->missing(),
            'unvollstaendig' => !$this->identity->isComplete(),
            'app_url' => $this->appUrl,
            'disclaimer_titel' => Disclaimer::TITLE,
            'disclaimer_text' => Disclaimer::BODY,
        ]));
    }
}
