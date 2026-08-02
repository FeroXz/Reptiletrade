<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Loest einen Textschluessel zur passenden Rechtsordnung auf.
 *
 * Reihenfolge: angefragte Rechtsordnung, dann EU, dann DE. Fehlt der Text
 * ganz, entsteht ein als fehlend markierter Platzhalter — die Anzeige laeuft
 * weiter, aber das Admin-Dashboard und die Tests sehen die Luecke.
 */
final readonly class LegalTextResolver
{
    public function __construct(private LegalTextRepository $repository) {}

    public function resolve(
        string $key,
        string $jurisdiction = 'DE',
        NoticeSeverity $severity = NoticeSeverity::Info,
    ): LegalNotice {
        foreach ($this->fallbackChain($jurisdiction) as $candidate) {
            $text = $this->repository->find($key, $candidate);
            if ($text !== null) {
                return $text->toNotice($severity);
            }
        }

        return new LegalNotice(
            $key,
            'Rechtstext fehlt',
            \sprintf('Für den Schlüssel "%s" ist kein Rechtstext hinterlegt. Bitte im Admin ergänzen.', $key),
            null,
            NoticeSeverity::Kritisch,
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function fallbackChain(string $jurisdiction): array
    {
        $chain = [$jurisdiction];

        foreach (['EU', 'DE'] as $fallback) {
            if (!\in_array($fallback, $chain, true)) {
                $chain[] = $fallback;
            }
        }

        return $chain;
    }
}
