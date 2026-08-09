<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;

/**
 * Weiterleitungen anlegen, aufloesen und vor sich selbst schuetzen.
 *
 * Zwei Regeln tragen alles Weitere:
 *
 * 1. **Ketten werden beim Anlegen aufgeloest, nicht beim Ausliefern.** Zeigt
 *    /a/ auf /b/ und kommt nun /b/ → /c/ dazu, wird /a/ gleich mit auf /c/
 *    gezogen. Sonst schickt jede Umbenennung den Besucher einen Sprung weiter
 *    durch die Geschichte der Seite — und Suchmaschinen geben nach wenigen
 *    Spruengen auf.
 * 2. **Schleifen werden abgewiesen, nicht abgeschnitten.** Eine Weiterleitung,
 *    die im Kreis fuehrt, ist ein Fehler in der Absicht des Redakteurs; ihn
 *    stillschweigend zu begradigen hiesse zu raten, was gemeint war.
 */
final readonly class RedirectService
{
    /**
     * Wie tief eine Kette beim Aufloesen verfolgt wird. Zehn ist grosszuegig:
     * In der Praxis entsteht selten mehr als eine Stufe, und die Schranke
     * faengt ab, was ein von Hand veraenderter Datenbestand anrichten koennte.
     */
    private const int MAX_DEPTH = 10;

    public function __construct(
        private RedirectRepository $redirects,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Legt eine Weiterleitung an.
     *
     * @throws ContentException
     */
    public function create(string $fromPath, string $toPath, ?int $actorId, bool $auto = false, int $code = 301): Redirect
    {
        $from = ContentPath::normalize($fromPath);
        $to = self::normalizeTarget($toPath);

        if ($from === $to) {
            throw new ContentException('Eine Weiterleitung auf sich selbst führt nirgendwohin.');
        }

        if (!\in_array($code, [301, 302], true)) {
            throw new ContentException('Nur 301 (dauerhaft) und 302 (vorübergehend) sind zulässig.');
        }

        // Bei einer automatischen Weiterleitung — also nach einer Umbenennung —
        // ist bekannt, dass unter dem Ziel jetzt wirklich eine Seite steht.
        // Eine Weiterleitung, die von genau dort wegfuehrt, stammt aus einer
        // frueheren Umbenennung und ist damit ueberholt.
        //
        // Ohne diesen Schritt scheitert der haeufigste Sonderfall: Jemand
        // benennt zurueck. Dann zeigte die alte Weiterleitung auf den neuen
        // Namen und die neue auf den alten — ein Kreis, und die Rueckbenennung
        // bekaeme gar keine Weiterleitung.
        //
        // Von Hand angelegte Weiterleitungen raeumen hier nichts weg: Dort
        // weiss niemand, ob das Ziel eine Seite ist, und eine stillschweigend
        // geloeschte Weiterleitung des Betreibers waere schlimmer als eine
        // Fehlermeldung. Die faengt weiter unten der Schleifenschutz.
        if ($auto) {
            $this->redirects->deleteByPath($to);
        }

        // Zeigt das Ziel selbst weiter? Dann gleich auf das Ende der Kette.
        $to = $this->resolveTarget($to, $from);

        $vorhanden = $this->redirects->findByPath($from);

        $redirect = new Redirect(
            id: $vorhanden?->id,
            fromPath: $from,
            toPath: $to,
            code: $code,
            hits: $vorhanden->hits ?? 0,
            lastUsedAt: $vorhanden->lastUsedAt ?? null,
            createdAt: $vorhanden->createdAt ?? $this->clock->now(),
            createdBy: $vorhanden->createdBy ?? $actorId,
            isAuto: $auto,
        );

        $id = $this->redirects->save($redirect);

        // Alles, was bisher auf den alten Pfad zeigte, zeigt jetzt direkt aufs
        // Ziel — sonst waechst mit jeder Umbenennung eine Stufe dazu.
        $this->flatten($from, $to);

        $this->audit->record(new AuditEntry(
            'redirect.created',
            'content_redirect',
            $id,
            ['von' => $from, 'nach' => $to, 'code' => $code, 'automatisch' => $auto],
            $actorId,
            $actorId === null ? AuditActorType::System : AuditActorType::User,
        ));

        return new Redirect($id, $from, $to, $code, $redirect->hits, $redirect->lastUsedAt, $redirect->createdAt, $redirect->createdBy, $auto);
    }

    /**
     * Sucht die Weiterleitung zu einem Pfad und zaehlt den Treffer mit.
     */
    public function resolve(string $path): ?Redirect
    {
        $redirect = $this->redirects->findByPath(ContentPath::normalize($path));

        if ($redirect === null || $redirect->id === null) {
            return null;
        }

        $this->redirects->recordHit($redirect->id, $this->clock->now());

        return $redirect;
    }

    /**
     * @throws ContentException
     */
    public function delete(int $id, ?int $actorId): void
    {
        $this->redirects->delete($id);

        $this->audit->record(new AuditEntry(
            'redirect.deleted',
            'content_redirect',
            $id,
            [],
            $actorId,
        ));
    }

    /**
     * @return list<Redirect>
     */
    public function all(int $limit = 200): array
    {
        return $this->redirects->all($limit);
    }

    /**
     * Weiterleitungen, die im Kreis fuehren — fuer bin/doctor.php.
     *
     * @return list<array{from: string, to: string}>
     */
    public function loops(): array
    {
        return $this->redirects->loops();
    }

    /**
     * Folgt der Kette bis zum Ende und weist Schleifen ab.
     *
     * @throws ContentException
     */
    private function resolveTarget(string $to, string $from): string
    {
        $gesehen = [$from => true];
        $ziel = $to;

        for ($tiefe = 0; $tiefe < self::MAX_DEPTH; ++$tiefe) {
            if (isset($gesehen[$ziel])) {
                throw new ContentException(\sprintf(
                    'Diese Weiterleitung führte im Kreis (%s → %s). Sie wurde nicht angelegt.',
                    $from,
                    $to,
                ));
            }

            $gesehen[$ziel] = true;

            $naechste = str_starts_with($ziel, '/') ? $this->redirects->findByPath($ziel) : null;

            if ($naechste === null) {
                return $ziel;
            }

            $ziel = $naechste->toPath;
        }

        throw new ContentException('Die Weiterleitungskette ist zu lang. Räume sie zuerst auf.');
    }

    /**
     * Zieht alle Weiterleitungen, die auf $from zeigen, direkt auf $to.
     */
    private function flatten(string $from, string $to): void
    {
        foreach ($this->redirects->pointingTo($from) as $vorherige) {
            if ($vorherige->id === null || $vorherige->fromPath === $to) {
                continue;
            }

            $this->redirects->save(new Redirect(
                id: $vorherige->id,
                fromPath: $vorherige->fromPath,
                toPath: $to,
                code: $vorherige->code,
                hits: $vorherige->hits,
                lastUsedAt: $vorherige->lastUsedAt,
                createdAt: $vorherige->createdAt,
                createdBy: $vorherige->createdBy,
                isAuto: $vorherige->isAuto,
            ));
        }
    }

    /**
     * Ziele duerfen interne Pfade oder vollstaendige http(s)-Adressen sein.
     * Alles andere — allen voran javascript: — waere eine offene Weiterleitung
     * mit Code-Ausfuehrung.
     *
     * @throws ContentException
     */
    private static function normalizeTarget(string $target): string
    {
        $target = trim($target);
        $lower = strtolower($target);

        if (str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://')) {
            return $target;
        }

        if ($target === '' || !str_starts_with($target, '/')) {
            throw new ContentException('Das Ziel muss ein interner Pfad (/…) oder eine vollständige http(s)-Adresse sein.');
        }

        return ContentPath::normalize($target);
    }
}
