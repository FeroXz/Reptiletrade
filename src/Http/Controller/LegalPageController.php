<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Legal\Disclaimer;
use Reptilienmarkt\Legal\LegalPageService;
use Twig\Environment;

/**
 * Impressum, Datenschutzerklaerung, Nutzungsbedingungen.
 *
 * Der Inhalt kommt nicht aus dem Code: Die strukturierten Angaben stehen in
 * config/impressum.php und koennen ueber /admin/recht ueberschrieben werden,
 * die Fliesstexte liegen als Abschnitte in legal_texts. Beides ist Sache des
 * Betreibers und aendert sich, ohne dass jemand ein Template anfasst.
 *
 * Fehlen Pflichtangaben, sagt die Seite das sichtbar — ein Impressum mit
 * Platzhaltern sieht sonst aus wie ein fertiges.
 */
final readonly class LegalPageController
{
    public function __construct(
        private SiteIdentity $identity,
        private LegalPageService $pages,
        private Environment $twig,
        private string $appUrl,
    ) {}

    public function imprint(Request $request): Response
    {
        return $this->page('recht/impressum.html.twig', 'impressum');
    }

    public function privacy(Request $request): Response
    {
        return $this->page('recht/datenschutz.html.twig', 'datenschutz');
    }

    public function terms(Request $request): Response
    {
        return $this->page('recht/nutzungsbedingungen.html.twig', 'nutzungsbedingungen');
    }

    private function page(string $template, string $page): Response
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
            // Die Fliesstexte kommen aus legal_texts und sind ueber
            // /admin/recht/abschnitte pflegbar. Die strukturierten Angaben
            // daneben bleiben Felder — sie sind keine Prosa.
            'abschnitte' => $this->pages->rendered($page, $this->identity),
        ]));
    }
}
