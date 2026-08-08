<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Log\Logger;

/**
 * Schaltet geplante Inhalte frei.
 *
 * Ueber einen Auftrag und nicht ueber einen Request-Hook: Eine Seite, die erst
 * erscheint, wenn zufaellig jemand vorbeikommt, erscheint auf einer leisen
 * Website gar nicht — und auf einer lauten kaeme sie zwar puenktlich, aber der
 * Besucher bezahlte sie mit der Wartezeit.
 *
 * Idempotent: Ein zweiter Lauf findet nichts Faelliges mehr, weil der Status
 * beim ersten gewechselt ist.
 *
 * Nebenbei fallen die abgelaufenen Vorschaulinks weg. Das gehoert hierher und
 * nicht in einen eigenen Auftrag: Es ist ein Loeschbefehl auf einen Index,
 * laeuft in Millisekunden, und ein eigener Zeitplaneintrag dafuer waere mehr
 * Verwaltung als Arbeit.
 */
final readonly class ContentPublishHandler implements JobHandler
{
    public function __construct(
        private ContentService $content,
        private PreviewService $previews,
        private Clock $clock,
        private Logger $logger,
    ) {}

    public function type(): string
    {
        return 'content.publish';
    }

    public function handle(Job $job): string
    {
        $veroeffentlicht = $this->content->publishDue($this->clock->now());
        $abgelaufen = $this->previews->purgeExpired();

        foreach ($veroeffentlicht as $eintrag) {
            $this->logger->info('content.published', [
                'id' => $eintrag->id,
                'pfad' => $eintrag->path,
            ]);
        }

        if ($veroeffentlicht === [] && $abgelaufen === 0) {
            return 'Nichts faellig.';
        }

        return \sprintf(
            '%d freigeschaltet (%s), %d abgelaufene Vorschaulinks entfernt.',
            \count($veroeffentlicht),
            $veroeffentlicht === []
                ? '—'
                : implode(', ', array_map(static fn(ContentEntry $e): string => $e->path, $veroeffentlicht)),
            $abgelaufen,
        );
    }
}
