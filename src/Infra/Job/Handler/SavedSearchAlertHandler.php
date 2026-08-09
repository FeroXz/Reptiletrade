<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Search\AlertFrequency;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SavedSearch;
use Reptilienmarkt\Domain\Search\SavedSearchRepository;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Translator;

/**
 * Benachrichtigt ueber neue Treffer zu gespeicherten Suchen.
 *
 * Der Fortschritt haengt an last_seen_listing_id, nicht an einem Zeitstempel:
 * Kennungen sind lueckenlos aufsteigend, Zeitstempel koennen bei
 * Sommerzeitwechsel oder ungenauer Uhr springen. Mit der Kennung kann keine
 * Anzeige uebersprungen und keine doppelt gemeldet werden.
 *
 * Gesucht wird ueber den echten Suchdienst mit den gespeicherten Kriterien.
 * Vorher stand hier "die neuen aktiven Anzeigen seit dem letzten Lauf", ohne
 * Filter — was niemandem auffiel, weil die Tabelle leer war. Mit echten Zeilen
 * waere daraus eine taegliche Rundmail ueber den gesamten Neuzugang geworden.
 *
 * Ob die Mail den Empfaenger erreicht, entscheidet der Kanal suche.treffer:
 * Der Zweck steht an der Nachricht, den Rest erledigt der Umschlag um den
 * Mailer. Der Fortschritt wird trotzdem vermerkt — sonst sammelte sich ein
 * Rueckstand an, der beim Wiedereinschalten auf einen Schlag herauskaeme.
 */
final readonly class SavedSearchAlertHandler implements JobHandler
{
    private const int MAX_HITS_PER_MAIL = 10;

    public function __construct(
        private SavedSearchRepository $searches,
        private ListingSearchRepository $listings,
        private UserRepository $users,
        private Mailer $mailer,
        private Translator $translator,
        private Clock $clock,
        private string $appUrl = 'https://example.tld',
    ) {}

    public function type(): string
    {
        return 'saved_search.alert';
    }

    public function handle(Job $job): string
    {
        $frequenz = AlertFrequency::tryFrom($job->string('frequenz', 'taeglich')) ?? AlertFrequency::Taeglich;
        $benachrichtigt = 0;

        foreach ($this->searches->due($frequenz) as $suche) {
            if ($this->alert($suche)) {
                ++$benachrichtigt;
            }
        }

        return \sprintf('%d Benachrichtigungen (%s)', $benachrichtigt, $frequenz->value);
    }

    private function alert(SavedSearch $search): bool
    {
        $seit = $search->lastSeenListingId ?? 0;

        // Nach Neuigkeit sortiert, unabhaengig davon, wie der Nutzer die Liste
        // sortiert hatte: Hier geht es um das, was seit dem letzten Lauf
        // dazugekommen ist, nicht um den guenstigsten Treffer.
        $kriterien = $search->criteria
            ->withSort(SortOrder::Neueste)
            ->with(perPage: self::MAX_HITS_PER_MAIL);

        $treffer = [];

        foreach ($this->listings->search($kriterien)->listings as $anzeige) {
            if ($anzeige->id > $seit) {
                $treffer[] = $anzeige;
            }
        }

        if ($treffer === []) {
            return false;
        }

        $empfaenger = $this->users->findById($search->userId);

        if ($empfaenger === null || $empfaenger->email === '') {
            return false;
        }

        $zeilen = array_map(
            fn($anzeige): string => \sprintf(
                '- %s: %s/anzeige/%d/',
                $anzeige->title,
                rtrim($this->appUrl, '/'),
                $anzeige->id,
            ),
            $treffer,
        );

        $platzhalter = [
            'name' => $empfaenger->displayName,
            'suche' => $search->name,
            'treffer' => implode("\n", $zeilen),
            'link' => rtrim($this->appUrl, '/') . '/konto/suchen',
        ];

        $this->mailer->send(new MailMessage(
            $empfaenger->email,
            $this->translator->translate('mail.suchtreffer.betreff', $platzhalter),
            $this->translator->translate('mail.suchtreffer.text', $platzhalter),
            $empfaenger->displayName,
            NotificationChannel::SucheTreffer->value,
            $search->userId,
        ));

        // Die Treffer stehen nach Neuigkeit — der erste traegt die hoechste
        // Kennung und ist damit der neue Stand.
        $this->searches->markAlerted($search->id ?? 0, $treffer[0]->id, $this->clock->now());

        return true;
    }
}
