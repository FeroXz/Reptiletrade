<?php

declare(strict_types=1);

use Reptilienmarkt\Http\Controller\AccountController;
use Reptilienmarkt\Http\Controller\AnnouncementController;
use Reptilienmarkt\Http\Controller\ApiController;
use Reptilienmarkt\Http\Controller\AuthController;
use Reptilienmarkt\Http\Controller\BillingController;
use Reptilienmarkt\Http\Controller\LegalDocumentController;
use Reptilienmarkt\Http\Controller\ListingController;
use Reptilienmarkt\Http\Controller\ListingWizardController;
use Reptilienmarkt\Http\Controller\MarketController;
use Reptilienmarkt\Http\Controller\MediaController;
use Reptilienmarkt\Http\Controller\MessageController;
use Reptilienmarkt\Http\Controller\ModerationController;
use Reptilienmarkt\Http\Controller\PasswordResetController;
use Reptilienmarkt\Http\Controller\ProfileController;
use Reptilienmarkt\Http\Controller\ReportController;
use Reptilienmarkt\Http\Controller\SpeciesController;
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

return $router;
