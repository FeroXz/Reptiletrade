<?php

declare(strict_types=1);

use Reptilienmarkt\Http\Controller\AccountController;
use Reptilienmarkt\Http\Controller\AdminContentController;
use Reptilienmarkt\Http\Controller\AdminController;
use Reptilienmarkt\Http\Controller\AdminLegalController;
use Reptilienmarkt\Http\Controller\AdminListingController;
use Reptilienmarkt\Http\Controller\AdminMediaController;
use Reptilienmarkt\Http\Controller\AdminStructureController;
use Reptilienmarkt\Http\Controller\AdminUserController;
use Reptilienmarkt\Http\Controller\AnnouncementController;
use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\AuthController;
use Reptilienmarkt\Http\Controller\BillingController;
use Reptilienmarkt\Http\Controller\ContactController;
use Reptilienmarkt\Http\Controller\ContentController;
use Reptilienmarkt\Http\Controller\GeneticsController;
use Reptilienmarkt\Http\Controller\LegalDocumentController;
use Reptilienmarkt\Http\Controller\LegalPageController;
use Reptilienmarkt\Http\Controller\ListingController;
use Reptilienmarkt\Http\Controller\ListingManagementController;
use Reptilienmarkt\Http\Controller\ListingWizardController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\MediaController;
use Reptilienmarkt\Http\Controller\MessageController;
use Reptilienmarkt\Http\Controller\ModerationController;
use Reptilienmarkt\Http\Controller\NewsController;
use Reptilienmarkt\Http\Controller\PasswordResetController;
use Reptilienmarkt\Http\Controller\PrivacyController;
use Reptilienmarkt\Http\Controller\ProfileController;
use Reptilienmarkt\Http\Controller\ReportController;
use Reptilienmarkt\Http\Controller\SitemapController;
use Reptilienmarkt\Http\Controller\SpeciesController;
use Reptilienmarkt\Http\Controller\StatsController;
use Reptilienmarkt\Http\Routing\Router;

$router = new Router();

// Marktuebersicht. Die SEO-Pfade sind Abkuerzungen fuer Filter, die es auch
// als Query-Parameter gibt: /markt/bartagame/red-hypo/bayern/ entspricht
// /markt/?art=bartagame&morphs=red-hypo&region=bayern
$router->get('/', MarketController::class, 'home', 'startseite');
$router->get('/markt/', MarketController::class, 'search', 'markt');
$router->get('/markt/{art}/', MarketController::class, 'search', 'markt.art');
$router->get('/markt/{art}/{morphs}/', MarketController::class, 'search', 'markt.art.morphs');
$router->get('/markt/{art}/{morphs}/{region}/', MarketController::class, 'search', 'markt.art.morphs.region');

// Pflichtangaben und Kontakt — ohne Anmeldung erreichbar
$router->get('/impressum', LegalPageController::class, 'imprint', 'impressum');
$router->get('/datenschutz', LegalPageController::class, 'privacy', 'datenschutz');
$router->get('/nutzungsbedingungen', LegalPageController::class, 'terms', 'nutzungsbedingungen');
$router->get('/kontakt', ContactController::class, 'show', 'kontakt');
$router->post('/kontakt', ContactController::class, 'submit', 'kontakt.absenden');

// Artenprofil als Landingpage
$router->get('/art/{slug}/', SpeciesController::class, 'show', 'art');

// Konto
$router->get('/registrieren', AuthController::class, 'showRegister', 'registrieren');
$router->post('/registrieren', AuthController::class, 'register', 'registrieren.absenden');
$router->get('/anmelden', AuthController::class, 'showLogin', 'anmelden');
$router->post('/anmelden', AuthController::class, 'login', 'anmelden.absenden');
$router->post('/abmelden', AuthController::class, 'logout', 'abmelden');

// Passwort vergessen
$router->get('/passwort/vergessen', PasswordResetController::class, 'showRequest', 'passwort.vergessen');
$router->post('/passwort/vergessen', PasswordResetController::class, 'sendLink', 'passwort.vergessen.absenden');
$router->get('/passwort/neu', PasswordResetController::class, 'showReset', 'passwort.neu');
$router->post('/passwort/neu', PasswordResetController::class, 'reset', 'passwort.neu.absenden');

// Kontoverwaltung, Verifizierung, Zwei-Faktor
$router->get('/konto/', AccountController::class, 'show', 'konto');
$router->post('/konto/email-senden', AccountController::class, 'sendEmailVerification', 'konto.email.senden');
$router->get('/konto/email-bestaetigen', AccountController::class, 'confirmEmail', 'konto.email.bestaetigen');
$router->post('/konto/telefon', AccountController::class, 'startPhoneVerification', 'konto.telefon');
$router->post('/konto/telefon-bestaetigen', AccountController::class, 'confirmPhone', 'konto.telefon.bestaetigen');
$router->post('/konto/nachweis', AccountController::class, 'uploadDocument', 'konto.nachweis');
$router->post('/konto/passwort', AccountController::class, 'changePassword', 'konto.passwort');
$router->post('/konto/zwei-faktor', AccountController::class, 'setupTwoFactor', 'konto.zweifaktor');
$router->post('/konto/zwei-faktor/bestaetigen', AccountController::class, 'confirmTwoFactor', 'konto.zweifaktor.bestaetigen');
$router->post('/konto/zwei-faktor/aus', AccountController::class, 'disableTwoFactor', 'konto.zweifaktor.aus');

// Statistik der eigenen Anzeigen
$router->get('/konto/statistik', StatsController::class, 'show', 'statistik');

// Zuechterprofil
$router->get('/konto/profil', ProfileController::class, 'edit', 'profil.bearbeiten');
$router->post('/konto/profil', ProfileController::class, 'save', 'profil.speichern');
$router->get('/zuechter/{slug}/', ProfileController::class, 'show', 'profil');

// Postfach
$router->get('/postfach/', MessageController::class, 'inbox', 'postfach');
$router->post('/anzeige/{id}/nachricht', MessageController::class, 'start', 'postfach.starten');
$router->get('/postfach/{id}/', MessageController::class, 'show', 'postfach.gespraech');
$router->post('/postfach/{id}/senden', MessageController::class, 'send', 'postfach.senden');
$router->post('/postfach/{id}/handel', MessageController::class, 'confirmDeal', 'postfach.handel');
$router->post('/postfach/{id}/bewerten', MessageController::class, 'review', 'postfach.bewerten');

// Meldebutton
$router->get('/melden/{art}/{id}', ReportController::class, 'form', 'melden');
$router->post('/melden/{art}/{id}', ReportController::class, 'submit', 'melden.absenden');

// Tarife und Abrechnung (Phase 6 — vorbereitet, ohne aktiven Zahlungsanbieter
// zeigt die Tarifseite Preise und keine Kaufknoepfe)
$router->get('/tarife', BillingController::class, 'plans', 'tarife');
$router->get('/konto/abrechnung', BillingController::class, 'overview', 'abrechnung');
$router->post('/konto/abrechnung/tarif', BillingController::class, 'subscribe', 'abrechnung.tarif');
$router->post('/konto/abrechnung/kuendigen', BillingController::class, 'cancel', 'abrechnung.kuendigen');
$router->post('/anzeige/{id}/boost', BillingController::class, 'boost', 'anzeige.boost');
$router->get('/konto/zahlung/erfolg', BillingController::class, 'paymentReturn', 'zahlung.erfolg');
$router->get('/konto/zahlung/abbruch', BillingController::class, 'paymentCancelled', 'zahlung.abbruch');
$router->post('/api/v1/zahlungen/webhook', BillingController::class, 'webhook', 'zahlung.webhook');

// Nachzucht-Ankuendigungen (Merkmal des Zuechter-Tarifs)
$router->get('/konto/nachzuchten', AnnouncementController::class, 'index', 'nachzuchten');
$router->post('/konto/nachzuchten', AnnouncementController::class, 'create', 'nachzuchten.anlegen');
$router->post('/konto/nachzuchten/{id}/status', AnnouncementController::class, 'changeStatus', 'nachzuchten.status');

// Vererbungsrechner. Der POST beantwortet dieselbe Anfrage als Seite oder als
// JSON — je nachdem, was der Aufrufer im Accept-Kopf verlangt.
$router->get('/paarung/simulator', GeneticsController::class, 'form', 'genetik.rechner');
$router->post('/paarung/simulator', GeneticsController::class, 'simulate', 'genetik.rechnen');
$router->get('/paarung/simulator/{id}/pdf', GeneticsController::class, 'downloadPdf', 'genetik.pdf');
$router->get('/konto/genetik-berichte', GeneticsController::class, 'myReports', 'genetik.berichte');
$router->post('/konto/genetik-berichte/{id}/loeschen', GeneticsController::class, 'deleteReport', 'genetik.bericht.loeschen');

// DSGVO: Datenauskunft und Kontoloeschung
$router->get('/konto/daten', PrivacyController::class, 'show', 'daten');
$router->get('/konto/daten/export', PrivacyController::class, 'download', 'daten.export');
$router->post('/konto/loeschen', PrivacyController::class, 'delete', 'konto.loeschen');

// Administration (Rolle admin — Moderation reicht hier nicht)
$router->get('/admin/', AdminController::class, 'dashboard', 'admin');
$router->get('/admin/anzeigen', AdminListingController::class, 'index', 'admin.anzeigen');
$router->post('/admin/anzeige/{id}/pausieren', AdminListingController::class, 'pause', 'admin.anzeige.pausieren');
$router->post('/admin/anzeige/{id}/fortsetzen', AdminListingController::class, 'resume', 'admin.anzeige.fortsetzen');
$router->get('/admin/nutzer', AdminUserController::class, 'index', 'admin.nutzer');
$router->post('/admin/nutzer/{id}/sperren', AdminUserController::class, 'ban', 'admin.nutzer.sperren');
$router->post('/admin/nutzer/{id}/entsperren', AdminUserController::class, 'unban', 'admin.nutzer.entsperren');
$router->post('/admin/nutzer/{id}/loeschen', AdminUserController::class, 'delete', 'admin.nutzer.loeschen');
$router->get('/admin/kontakt', AdminUserController::class, 'contactQueue', 'admin.kontakt');
$router->post('/admin/kontakt/{id}/erledigt', AdminUserController::class, 'resolveContact', 'admin.kontakt.erledigt');
// Rechtsseiten: Stammdaten und Abschnitte. Nur Rolle admin — wer diese Seiten
// aendert, aendert, wofuer der Betreiber haftet.
$router->get('/admin/recht', AdminLegalController::class, 'index', 'admin.recht');
$router->post('/admin/recht', AdminLegalController::class, 'save', 'admin.recht.speichern');
$router->get('/admin/recht/abschnitte', AdminLegalController::class, 'sections', 'admin.recht.abschnitte');
$router->post('/admin/recht/abschnitte', AdminLegalController::class, 'saveSection', 'admin.recht.abschnitt.speichern');

$router->get('/admin/texte', AdminController::class, 'texts', 'admin.texte');
$router->post('/admin/texte', AdminController::class, 'saveTexts', 'admin.texte.speichern');
$router->get('/admin/artenstamm', AdminController::class, 'catalog', 'admin.artenstamm');
$router->get('/admin/artenstamm/{art}/export', AdminController::class, 'exportCatalog', 'admin.artenstamm.export');
$router->post('/admin/artenstamm/{art}/import', AdminController::class, 'importCatalog', 'admin.artenstamm.import');

// Redaktion (Rolle admin oder Eintrag in content_editors — fehlt beides, gibt
// es 404 statt 403, dieselbe Linie wie /admin/)
$router->get('/admin/inhalte', AdminContentController::class, 'index', 'admin.inhalte');
$router->get('/admin/inhalte/neu', AdminContentController::class, 'createForm', 'admin.inhalte.neu');
$router->post('/admin/inhalte/neu', AdminContentController::class, 'create', 'admin.inhalte.anlegen');
$router->get('/admin/inhalte/{id}/bearbeiten', AdminContentController::class, 'edit', 'admin.inhalte.bearbeiten');
$router->post('/admin/inhalte/{id}/bearbeiten', AdminContentController::class, 'save', 'admin.inhalte.speichern');
$router->post('/admin/inhalte/{id}/autosave', AdminContentController::class, 'autosave', 'admin.inhalte.autosave');
$router->post('/admin/inhalte/{id}/veroeffentlichen', AdminContentController::class, 'publish', 'admin.inhalte.veroeffentlichen');
$router->post('/admin/inhalte/{id}/zuruecknehmen', AdminContentController::class, 'unpublish', 'admin.inhalte.zuruecknehmen');
$router->post('/admin/inhalte/{id}/archivieren', AdminContentController::class, 'archive', 'admin.inhalte.archivieren');
$router->post('/admin/inhalte/{id}/loeschen', AdminContentController::class, 'delete', 'admin.inhalte.loeschen');
$router->get('/admin/inhalte/{id}/versionen', AdminContentController::class, 'revisions', 'admin.inhalte.versionen');
$router->post('/admin/inhalte/{id}/versionen/{nr}/zuruecksetzen', AdminContentController::class, 'restore', 'admin.inhalte.zuruecksetzen');
$router->post('/admin/inhalte/{id}/vorschau', AdminContentController::class, 'preview', 'admin.inhalte.vorschau');

// Mediathek. Loeschen ist zweistufig: erst die Verwendungen zeigen, dann loeschen.
// Menues und Weiterleitungen
$router->get('/admin/menues', AdminStructureController::class, 'menus', 'admin.menues');
$router->post('/admin/menues', AdminStructureController::class, 'saveMenuItem', 'admin.menues.speichern');
$router->post('/admin/menues/{id}/loeschen', AdminStructureController::class, 'deleteMenuItem', 'admin.menues.loeschen');
$router->get('/admin/weiterleitungen', AdminStructureController::class, 'redirects', 'admin.weiterleitungen');
$router->post('/admin/weiterleitungen', AdminStructureController::class, 'createRedirect', 'admin.weiterleitungen.anlegen');
$router->post('/admin/weiterleitungen/{id}/loeschen', AdminStructureController::class, 'deleteRedirect', 'admin.weiterleitungen.loeschen');

$router->get('/admin/medien', AdminMediaController::class, 'index', 'admin.medien');
$router->post('/admin/medien', AdminMediaController::class, 'upload', 'admin.medien.hochladen');
$router->post('/admin/medien/{id}/beschreiben', AdminMediaController::class, 'describe', 'admin.medien.beschreiben');
$router->get('/admin/medien/{id}/loeschen', AdminMediaController::class, 'confirmDelete', 'admin.medien.loeschen.fragen');
$router->post('/admin/medien/{id}/loeschen', AdminMediaController::class, 'delete', 'admin.medien.loeschen');

// Moderation
$router->get('/moderation/', ModerationController::class, 'queue', 'moderation');
$router->post('/moderation/meldung/{id}', ModerationController::class, 'resolveReport', 'moderation.meldung');
$router->post('/moderation/anzeige/{id}', ModerationController::class, 'decideListing', 'moderation.anzeige');
$router->post('/moderation/nachweis/{id}', ModerationController::class, 'decideDocument', 'moderation.nachweis');

// Anzeigenassistent
$router->get('/meine-anzeigen/', ListingWizardController::class, 'mine', 'meine-anzeigen');
$router->get('/anzeige/neu', ListingWizardController::class, 'start', 'anzeige.neu');
$router->post('/anzeige/neu', ListingWizardController::class, 'create', 'anzeige.anlegen');
$router->get('/anzeige/{id}/schritt/{schritt}', ListingWizardController::class, 'step', 'anzeige.schritt');
$router->post('/anzeige/{id}/schritt/{schritt}', ListingWizardController::class, 'save', 'anzeige.schritt.speichern');
$router->post('/anzeige/{id}/autosave/{schritt}', ListingWizardController::class, 'autosave', 'anzeige.autosave');
$router->post('/anzeige/{id}/veroeffentlichen', ListingWizardController::class, 'publish', 'anzeige.veroeffentlichen');

// Anzeige verwalten (nach der Veroeffentlichung)
$router->get('/anzeige/{id}/bearbeiten', ListingManagementController::class, 'edit', 'anzeige.bearbeiten');
$router->post('/anzeige/{id}/bearbeiten', ListingManagementController::class, 'update', 'anzeige.bearbeiten.speichern');
$router->post('/anzeige/{id}/pausieren', ListingManagementController::class, 'pause', 'anzeige.pausieren');
$router->post('/anzeige/{id}/fortsetzen', ListingManagementController::class, 'resume', 'anzeige.fortsetzen');
$router->post('/anzeige/{id}/loeschen', ListingManagementController::class, 'delete', 'anzeige.loeschen');

// Medien und Nachweise
$router->post('/anzeige/{id}/bilder', MediaController::class, 'uploadImage', 'anzeige.bild.hochladen');
$router->post('/anzeige/{id}/bilder/{media}/loeschen', MediaController::class, 'deleteImage', 'anzeige.bild.loeschen');
$router->post('/anzeige/{id}/bilder/{media}/titelbild', MediaController::class, 'setPrimaryImage', 'anzeige.bild.titel');
$router->post('/anzeige/{id}/nachweise', MediaController::class, 'uploadLegalDocument', 'anzeige.nachweis.hochladen');

// Rechtsnachweise liegen ausserhalb des Webroots — ausschliesslich hierueber abrufbar.
$router->get('/nachweis/{id}', LegalDocumentController::class, 'download', 'nachweis.download');

// Oeffentliche Detailseite
$router->get('/anzeige/{id}/', ListingController::class, 'show', 'anzeige.detail');

// REST-API auf denselben Domain-Services — Grundlage der spaeteren PWA
$router->get('/api/v1/listings', ApiController::class, 'listings', 'api.listings');
$router->get('/api/v1/arten', ApiController::class, 'species', 'api.arten');
$router->get('/api/v1/arten/{slug}/morphs', ApiController::class, 'morphs', 'api.arten.morphs');
$router->get('/api/v1/orte', ApiController::class, 'places', 'api.orte');

// Beitraege: Uebersicht, Kategoriearchiv, Feed. Die Einzelseite eines Beitrags
// laeuft ueber die Auffangroute — sie ist eine Inhaltsseite wie jede andere,
// nur mit einem Pfad, der das Jahr traegt.
$router->get('/sitemap.xml', SitemapController::class, 'sitemap', 'sitemap');
$router->get('/robots.txt', SitemapController::class, 'robots', 'robots');

$router->get('/news/', NewsController::class, 'index', 'news');
$router->get('/news/kategorie/{slug}/', NewsController::class, 'category', 'news.kategorie');
$router->get('/feed.xml', NewsController::class, 'feed', 'news.feed');

// Signierte Vorschau eines Entwurfs — 24 Stunden gueltig, fuer jemanden, der
// sich nicht anmelden kann.
$router->get('/vorschau/{token}', ContentController::class, 'preview', 'inhalt.vorschau');

// Redaktionelle Seiten. Diese Route steht mit Absicht als LETZTE: Sie passt auf
// jeden Pfad, den bis hierhin niemand beansprucht hat. Damit ein Redakteur
// nicht in ein Schweigen hineinspeichert, prueft ReservedPaths beim Anlegen —
// und tests/Http/ReservedPathsTest haelt die Liste gegen genau diese Datei.
$router->fallback('/{pfad*}', ContentController::class, 'show', 'inhalt.seite');

return $router;
