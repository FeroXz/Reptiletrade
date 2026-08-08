<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use DateTimeImmutable;
use Exception;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentBlockRepository;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentRevisionRepository;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentTemplate;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Storage\MediaService;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Die Redaktionsoberflaeche.
 *
 * Der Editor ist ein serverseitig gerendertes Formular: Bloecke werden ueber
 * echte Knoepfe hinzugefuegt, verschoben und geloescht, jede Aktion ist ein
 * POST mit CSRF-Token. Ohne Javascript ist er vollstaendig bedienbar;
 * public/assets/inhalt.js verbessert ihn nachtraeglich.
 *
 * Fehlende Berechtigung ergibt 404, nicht 403 — dieselbe Linie wie /admin/:
 * Wer nicht hingehoert, soll nicht erfahren, dass es die Seite gibt.
 */
final readonly class AdminContentController
{
    public function __construct(
        private ContentService $content,
        private ContentEntryRepository $entries,
        private ContentBlockRepository $blocks,
        private ContentRevisionRepository $revisions,
        private PreviewService $previews,
        private MediaService $media,
        private ContentPermission $permission,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $this->requireEditor();

        $type = $this->enum(ContentType::class, $request->queryString('typ'));
        $status = $this->enum(ContentStatus::class, $request->queryString('status'));

        return Response::html($this->twig->render('admin/inhalte.html.twig', [
            'eintraege' => $this->entries->search($type, $status, $request->queryString('q')),
            'typen' => ContentType::cases(),
            'zustaende' => ContentStatus::cases(),
            'typ' => $request->queryString('typ', ''),
            'status' => $request->queryString('status', ''),
            'suche' => $request->queryString('q', ''),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function createForm(Request $request): Response
    {
        $this->requireEditor();

        return Response::html($this->twig->render('admin/inhalt_neu.html.twig', [
            'typen' => ContentType::cases(),
            'seiten' => $this->entries->search(ContentType::Seite, null, null),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function create(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        try {
            $entry = $this->content->create(
                $this->enum(ContentType::class, $this->text($request, 'typ')) ?? ContentType::Seite,
                $this->text($request, 'titel'),
                $this->text($request, 'slug') === '' ? null : $this->text($request, 'slug'),
                $this->id($request, 'eltern_id'),
                $user->id ?? 0,
            );
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/inhalte/neu');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.inhalt.angelegt'));

        return Response::redirect('/admin/inhalte/' . ($entry->id ?? 0) . '/bearbeiten');
    }

    public function edit(Request $request): Response
    {
        $this->requireEditor();
        $entry = $this->entry($request);

        return Response::html($this->twig->render('admin/inhalt_bearbeiten.html.twig', [
            'eintrag' => $entry,
            'bloecke' => $this->blocks->forEntry($entry->id ?? 0),
            'blocktypen' => BlockType::choices(),
            'vorlagen' => ContentTemplate::cases(),
            'seiten' => array_values(array_filter(
                $this->entries->search(ContentType::Seite, null, null),
                static fn($kandidat): bool => $kandidat->id !== $entry->id,
            )),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * GET /admin/inhalte/{id}/versionen
     */
    public function revisions(Request $request): Response
    {
        $this->requireEditor();
        $entry = $this->entry($request);

        return Response::html($this->twig->render('admin/inhalt_versionen.html.twig', [
            'eintrag' => $entry,
            'fassungen' => $this->revisions->forEntry($entry->id ?? 0),
            'aufbewahrung' => $this->revisions->count($entry->id ?? 0),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * POST /admin/inhalte/{id}/versionen/{nr}/zuruecksetzen
     */
    public function restore(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $id = $entry->id ?? 0;
        $nr = $request->attribute('nr');

        try {
            $this->content->restore($id, $nr !== null && ctype_digit($nr) ? (int) $nr : 0, $user->id ?? 0);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/inhalte/' . $id . '/versionen');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.inhalt.zurueckgesetzt'));

        return Response::redirect('/admin/inhalte/' . $id . '/bearbeiten');
    }

    /**
     * POST /admin/inhalte/{id}/vorschau
     *
     * Erzeugt einen Link, der einen Entwurf 24 Stunden lang zeigt — fuer
     * jemanden, der sich nicht anmelden kann. Der Klartext erscheint einmal als
     * Meldung und ist danach nirgends mehr abrufbar.
     */
    public function preview(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $token = $this->previews->create($entry->id ?? 0, $user->id ?? 0);

        $this->session->flash('erfolg', $this->translator->translate('admin.inhalt.vorschau_link', [
            'link' => $this->previews->path($token),
            'stunden' => PreviewService::LIFETIME_HOURS,
        ]));

        return Response::redirect('/admin/inhalte/' . ($entry->id ?? 0) . '/bearbeiten');
    }

    /**
     * Ein Formular, mehrere Knoepfe. Welcher gedrueckt wurde, steht in
     * "aktion" — so kommt der Editor ohne Javascript aus und schickt trotzdem
     * bei jeder Blockaenderung den vollstaendigen Stand mit.
     */
    public function save(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $id = $entry->id ?? 0;
        $ziel = '/admin/inhalte/' . $id . '/bearbeiten';

        try {
            $this->content->updateHeader($id, [
                'titel' => $this->text($request, 'titel'),
                'slug' => $this->text($request, 'slug'),
                'anriss' => $this->text($request, 'anriss'),
                'meta_titel' => $this->nullable($request, 'meta_titel'),
                'meta_beschreibung' => $this->nullable($request, 'meta_beschreibung'),
                'noindex' => ($request->body['noindex'] ?? '') !== '',
                'vorlage' => $this->enum(ContentTemplate::class, $this->text($request, 'vorlage')) ?? $entry->template,
                'eltern_id' => $this->id($request, 'eltern_id'),
            ], $user->id ?? 0);

            $blocks = $this->applyAction($request, $this->readBlocks($request));

            $this->content->saveBlocks($id, $blocks, $user->id ?? 0);

            // Die Verwendungen wandern mit: Wer einen Bildblock loescht, denkt
            // nicht an die Verwendungstabelle — und eine Verwendung, die
            // stehenbleibt, sperrt das Bild fuer immer gegen das Loeschen.
            $this->media->syncUsages($id, $blocks, $entry->ogImageId);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect($ziel);
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.inhalt.gespeichert'));

        return Response::redirect($ziel);
    }

    /**
     * POST /admin/inhalte/{id}/autosave
     *
     * Derselbe Weg wie das Speichern, nur ohne Weiterleitung und ohne
     * Flash-Meldung. Bewusst dieselbe Verarbeitung: Ein zweiter, "leichterer"
     * Speicherpfad waere ein zweites Verhalten, das irgendwann vom ersten
     * abweicht — und Autosave ist genau die Stelle, an der das niemandem
     * auffiele.
     */
    public function autosave(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $id = $entry->id ?? 0;

        try {
            $this->content->updateHeader($id, [
                'titel' => $this->text($request, 'titel'),
                'slug' => $this->text($request, 'slug'),
                'anriss' => $this->text($request, 'anriss'),
                'meta_titel' => $this->nullable($request, 'meta_titel'),
                'meta_beschreibung' => $this->nullable($request, 'meta_beschreibung'),
                'noindex' => ($request->body['noindex'] ?? '') !== '',
                'vorlage' => $this->enum(ContentTemplate::class, $this->text($request, 'vorlage')) ?? $entry->template,
                'eltern_id' => $this->id($request, 'eltern_id'),
            ], $user->id ?? 0);

            $blocks = $this->readBlocks($request);
            $this->content->saveBlocks($id, $blocks, $user->id ?? 0, 'Autosave');
            $this->media->syncUsages($id, $blocks, $entry->ogImageId);
        } catch (ContentException $exception) {
            return Response::json(['gespeichert' => false, 'fehler' => $exception->getMessage()], 422);
        }

        return Response::json(['gespeichert' => true]);
    }

    public function publish(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $id = $entry->id ?? 0;

        try {
            $termin = $this->moment($this->text($request, 'termin'));
            $veroeffentlicht = $this->content->publish($id, $user->id ?? 0, $termin);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/inhalte/' . $id . '/bearbeiten');
        }

        $this->session->flash('erfolg', $this->translator->translate(
            $veroeffentlicht->status === ContentStatus::Geplant ? 'admin.inhalt.geplant' : 'admin.inhalt.veroeffentlicht',
        ));

        return Response::redirect('/admin/inhalte/' . $id . '/bearbeiten');
    }

    public function unpublish(Request $request): Response
    {
        return $this->statusChange(
            $request,
            fn(int $id, int $actorId): mixed => $this->content->unpublish($id, $actorId),
            'admin.inhalt.zurueckgenommen',
        );
    }

    public function archive(Request $request): Response
    {
        return $this->statusChange(
            $request,
            fn(int $id, int $actorId): mixed => $this->content->archive($id, $actorId),
            'admin.inhalt.archiviert',
        );
    }

    public function delete(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);

        try {
            $this->content->delete($entry->id ?? 0, $user->id ?? 0);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/inhalte/' . ($entry->id ?? 0) . '/bearbeiten');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.inhalt.geloescht'));

        return Response::redirect('/admin/inhalte');
    }

    // ------------------------------------------------------------- Bloecke

    /**
     * Liest die Blockliste aus dem Formular.
     *
     * Die Felder heissen block[0][typ], block[0][text] … — die Reihenfolge im
     * Feld ist die Reihenfolge auf der Seite. Ein unbekannter Typ wird
     * uebergangen statt zu einem Fehler: Er kann nur aus einem manipulierten
     * Formular stammen, und der Rest der Seite soll deswegen nicht verloren
     * gehen.
     *
     * @return list<ContentBlock>
     */
    private function readBlocks(Request $request): array
    {
        $raw = $request->body['block'] ?? null;

        if (!\is_array($raw)) {
            return [];
        }

        $blocks = [];
        $position = 0;

        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $type = BlockType::tryFrom(\is_string($entry['typ'] ?? null) ? $entry['typ'] : '');

            if ($type === null) {
                continue;
            }

            $blocks[] = new ContentBlock(null, $type, $position, $this->blockData($type, $entry));
            ++$position;
        }

        return $blocks;
    }

    /**
     * Die Nutzdaten eines Blocks — je Typ nur die Felder, die er kennt.
     *
     * Bewusst keine Uebernahme des ganzen Formularfelds: Sonst landet alles,
     * was jemand mitschickt, in data_json und von dort in die Ausgabe.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function blockData(BlockType $type, array $entry): array
    {
        $string = static function (string $key) use ($entry): string {
            $value = $entry[$key] ?? null;

            return \is_string($value) ? trim($value) : '';
        };

        $ints = static function (string $key) use ($entry): array {
            $value = $entry[$key] ?? null;
            $source = \is_string($value) ? explode(',', $value) : (\is_array($value) ? $value : []);

            $ids = [];
            foreach ($source as $candidate) {
                if (is_numeric($candidate) && (int) $candidate > 0) {
                    $ids[] = (int) $candidate;
                }
            }

            return $ids;
        };

        return match ($type) {
            BlockType::Text => ['text' => $string('text')],
            BlockType::Zitat => ['text' => $string('text'), 'quelle' => $string('quelle')],
            BlockType::Hinweis => ['titel' => $string('titel'), 'text' => $string('text')],
            BlockType::Cta => [
                'titel' => $string('titel'),
                'text' => $string('text'),
                'label' => $string('label'),
                'ziel' => $this->safeTarget($string('ziel')),
            ],
            BlockType::Bild => [
                'media_id' => (int) ($ints('media_id')[0] ?? 0),
                'alt_text' => $string('alt_text'),
                'bildunterschrift' => $string('bildunterschrift'),
            ],
            BlockType::Galerie => ['media_ids' => $ints('media_ids')],
            BlockType::AnzeigenTeaser => ['listing_id' => (int) ($ints('listing_id')[0] ?? 0)],
            BlockType::ArtenTeaser => ['art_slug' => $string('art_slug')],
            BlockType::Trenner => [],
        };
    }

    /**
     * Nur interne Ziele und http(s). Ein javascript:-Ziel im CTA-Knopf waere
     * derselbe Angriff wie im Markdown-Link, nur ohne Renderer davor.
     */
    private function safeTarget(string $target): string
    {
        if ($target === '') {
            return '';
        }

        $lower = strtolower($target);

        if (str_starts_with($target, '/') || str_starts_with($target, '#')) {
            return $target;
        }

        return str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://') ? $target : '';
    }

    /**
     * Hinzufuegen, Verschieben, Loeschen — alles auf der eingelesenen Liste,
     * bevor sie gespeichert wird. Damit ist jede Blockaktion ein gewoehnliches
     * Absenden des Formulars und braucht kein Javascript.
     *
     * @param list<ContentBlock> $blocks
     *
     * @return list<ContentBlock>
     */
    private function applyAction(Request $request, array $blocks): array
    {
        $action = $this->text($request, 'aktion');

        if ($action === '' || $action === 'speichern') {
            return $blocks;
        }

        if (str_starts_with($action, 'block-hinzufuegen')) {
            // Ohne Javascript traegt der Knopf nur "block-hinzufuegen"; welcher
            // Typ gemeint ist, steht im Auswahlfeld daneben. Mit Javascript
            // schreibt inhalt.js den Typ direkt in den Knopf, damit ein
            // Doppelklick nicht zwei verschiedene Bloecke anlegt.
            $wanted = str_contains($action, ':')
                ? substr($action, strpos($action, ':') + 1)
                : $this->blockChoice($request);

            $type = BlockType::tryFrom($wanted);

            if ($type !== null) {
                $blocks[] = new ContentBlock(null, $type, \count($blocks), $this->blockData($type, []));
            }

            return $blocks;
        }

        [$verb, $index] = array_pad(explode(':', $action, 2), 2, '');
        $index = is_numeric($index) ? (int) $index : -1;

        if (!isset($blocks[$index])) {
            return $blocks;
        }

        return match ($verb) {
            'block-loeschen' => array_values(array_filter(
                $blocks,
                static fn(ContentBlock $block, int $position): bool => $position !== $index,
                \ARRAY_FILTER_USE_BOTH,
            )),
            'block-hoch' => $this->swap($blocks, $index, $index - 1),
            'block-runter' => $this->swap($blocks, $index, $index + 1),
            default => $blocks,
        };
    }

    /**
     * Der im Auswahlfeld stehende Blocktyp. Das Feld traegt denselben Wert wie
     * der Knopf mit Javascript — so gibt es nur eine Schreibweise zu pflegen.
     */
    private function blockChoice(Request $request): string
    {
        $value = $this->text($request, 'neuer_block');
        $colon = strpos($value, ':');

        return $colon === false ? $value : substr($value, $colon + 1);
    }

    /**
     * @param list<ContentBlock> $blocks
     *
     * @return list<ContentBlock>
     */
    private function swap(array $blocks, int $a, int $b): array
    {
        if (!isset($blocks[$a], $blocks[$b])) {
            return $blocks;
        }

        [$blocks[$a], $blocks[$b]] = [$blocks[$b], $blocks[$a]];

        // array_values stellt die lueckenlose Liste wieder her, die die
        // Signatur zusagt — nach einer Zuweisung ueber den Index ist das nicht
        // mehr aus dem Code ablesbar.
        return array_values($blocks);
    }

    // ------------------------------------------------------------- Helfer

    /**
     * Der gemeinsame Rahmen der Statuswechsel: Berechtigung, CSRF, Eintrag,
     * Fehlerbehandlung, Meldung, Weiterleitung. Was sich unterscheidet, ist
     * genau ein Aufruf — der kommt als Closure herein statt als Methodenname,
     * damit die statische Analyse ihn noch pruefen kann.
     *
     * @param callable(int, int): mixed $aktion
     */
    private function statusChange(Request $request, callable $aktion, string $messageKey): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $entry = $this->entry($request);
        $id = $entry->id ?? 0;

        try {
            $aktion($id, $user->id ?? 0);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/inhalte/' . $id . '/bearbeiten');
        }

        $this->session->flash('erfolg', $this->translator->translate($messageKey));

        return Response::redirect('/admin/inhalte/' . $id . '/bearbeiten');
    }

    private function requireEditor(): User
    {
        $user = $this->currentUser->require();

        if (!$this->permission->mayEdit($user)) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }

    private function entry(Request $request): \Reptilienmarkt\Domain\Content\ContentEntry
    {
        $id = $request->attribute('id');
        $entry = $id !== null && ctype_digit($id) ? $this->entries->findById((int) $id) : null;

        if ($entry === null) {
            throw HttpException::notFound('Diesen Inhalt gibt es nicht.');
        }

        return $entry;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->body[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    private function nullable(Request $request, string $key): ?string
    {
        $value = $this->text($request, $key);

        return $value === '' ? null : $value;
    }

    private function id(Request $request, string $key): ?int
    {
        $value = $this->text($request, $key);

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Ein Termin aus dem Formular (datetime-local, also lokale Zeit ohne Zone).
     *
     * Leer heisst "jetzt". Unlesbares heisst nicht stillschweigend "jetzt" —
     * das waere die Sorte Fehler, die erst auffaellt, wenn ein Beitrag zu
     * frueh online steht.
     *
     * @throws ContentException
     */
    private function moment(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ContentException(\sprintf('"%s" ist kein lesbarer Termin.', $value));
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private function enum(string $enum, ?string $value): ?object
    {
        return $value === null || $value === '' ? null : $enum::tryFrom($value);
    }
}
