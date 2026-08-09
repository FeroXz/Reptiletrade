<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\MenuItem;
use Reptilienmarkt\Domain\Content\MenuRepository;
use Reptilienmarkt\Domain\Content\MenuTargetType;
use Reptilienmarkt\Domain\Content\MenuVisibility;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Menues und Weiterleitungen — die Struktur um die Inhalte herum.
 */
final readonly class AdminStructureController
{
    public function __construct(
        private MenuRepository $menus,
        private RedirectService $redirects,
        private ContentEntryRepository $entries,
        private ContentPermission $permission,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    // -------------------------------------------------------------- Menues

    public function menus(Request $request): Response
    {
        $this->requireEditor();

        $slug = $request->queryString('menu', 'hauptmenu') ?? 'hauptmenu';
        $menus = $this->menus->menus();

        if (!isset($menus[$slug])) {
            $slug = array_key_first($menus) ?? 'hauptmenu';
        }

        return Response::html($this->twig->render('admin/menues.html.twig', [
            'menues' => $menus,
            'menu' => $slug,
            'eintraege' => $this->menus->items($slug),
            'seiten' => $this->entries->search(null, ContentStatus::Veroeffentlicht, null),
            'zieltypen' => MenuTargetType::cases(),
            'sichtbarkeiten' => MenuVisibility::cases(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function saveMenuItem(Request $request): Response
    {
        $this->requireEditor();
        $this->session->assertCsrf($request);

        $slug = $this->text($request, 'menu');
        $menuId = $this->menus->menuId($slug);
        $ziel = '/admin/menues?menu=' . rawurlencode($slug);

        if ($menuId === null) {
            $this->session->flash('fehler', $this->translator->translate('admin.menue.unbekannt'));

            return Response::redirect($ziel);
        }

        $label = $this->text($request, 'label');
        $wert = $this->text($request, 'ziel_wert');

        if ($label === '' || $wert === '') {
            $this->session->flash('fehler', $this->translator->translate('admin.menue.unvollstaendig'));

            return Response::redirect($ziel);
        }

        $this->menus->saveItem(new MenuItem(
            id: null,
            menuId: $menuId,
            label: $label,
            targetType: MenuTargetType::tryFrom($this->text($request, 'ziel_typ')) ?? MenuTargetType::Route,
            targetValue: $wert,
            parentId: null,
            sortOrder: (int) ($this->text($request, 'reihenfolge') ?: '0'),
            visibility: MenuVisibility::tryFrom($this->text($request, 'sichtbarkeit')) ?? MenuVisibility::Alle,
        ));

        $this->session->flash('erfolg', $this->translator->translate('admin.menue.gespeichert'));

        return Response::redirect($ziel);
    }

    public function deleteMenuItem(Request $request): Response
    {
        $this->requireEditor();
        $this->session->assertCsrf($request);

        $this->menus->deleteItem($this->id($request));
        $this->session->flash('erfolg', $this->translator->translate('admin.menue.geloescht'));

        return Response::redirect('/admin/menues?menu=' . rawurlencode($this->text($request, 'menu')));
    }

    // ------------------------------------------------------- Weiterleitungen

    public function redirects(Request $request): Response
    {
        $this->requireEditor();

        return Response::html($this->twig->render('admin/weiterleitungen.html.twig', [
            'weiterleitungen' => $this->redirects->all(),
            'schleifen' => $this->redirects->loops(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function createRedirect(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        try {
            $this->redirects->create(
                $this->text($request, 'von'),
                $this->text($request, 'nach'),
                $user->id,
                auto: false,
                code: $this->text($request, 'code') === '302' ? 302 : 301,
            );
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/weiterleitungen');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.weiterleitung.angelegt'));

        return Response::redirect('/admin/weiterleitungen');
    }

    public function deleteRedirect(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $this->redirects->delete($this->id($request), $user->id);
        $this->session->flash('erfolg', $this->translator->translate('admin.weiterleitung.geloescht'));

        return Response::redirect('/admin/weiterleitungen');
    }

    // -------------------------------------------------------------- Helfer

    private function id(Request $request): int
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Diesen Eintrag gibt es nicht.');
        }

        return (int) $id;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->body[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    private function requireEditor(): User
    {
        $user = $this->currentUser->require();

        if (!$this->permission->mayEdit($user)) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }
}
