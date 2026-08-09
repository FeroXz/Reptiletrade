#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verwaltungskonten anlegen und verwalten.
 *
 * Es gibt bewusst kein voreingestelltes Administratorkonto und keinen
 * Einrichtungsassistenten im Browser: Ein mitgeliefertes Standardpasswort wird
 * vergessen, und eine offene Einrichtungsseite ist so lange eine offene Tuer,
 * wie sie niemand schliesst. Wer Zugriff auf die Kommandozeile hat, hat ohnehin
 * Zugriff auf die Datenbank — genau dort und nirgends sonst entsteht der erste
 * Administrator.
 *
 *   php bin/admin.php anlegen --email=… [--name=…]      Neues Konto mit Rolle admin
 *   php bin/admin.php ernennen --email=… [--rolle=…]    Vorhandenes Konto befoerdern
 *   php bin/admin.php entziehen --email=…               Redaktionsrecht zuruecknehmen
 *   php bin/admin.php passwort --email=…                Neues Passwort setzen
 *   php bin/admin.php liste                             Verwaltung und Redaktion
 *
 * Das Passwort wird abgefragt, nicht als Argument uebergeben: Argumente stehen
 * in der Shell-Historie und in der Prozessliste.
 */

use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\RegistrationException;
use Reptilienmarkt\Domain\Content\ContentEditorRepository;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\VerificationRepository;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("bin/admin.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$befehl = $argv[1] ?? 'hilfe';

// Von Hand statt mit getopt(): getopt() hoert beim ersten Argument ohne
// Bindestrich auf zu lesen — und das ist hier der Befehl.
$optionen = optionenLesen(array_slice($argv, 2));
$email = trim($optionen['email'] ?? '');
$name = trim($optionen['name'] ?? '');

$users = $container->get(UserRepository::class);
$verification = $container->get(VerificationRepository::class);
$auth = $container->get(AuthenticationService::class);
$hasher = $container->get(PasswordHasher::class);
$clock = $container->get(Clock::class);
$database = $container->get(Database::class);
$editors = $container->get(ContentEditorRepository::class);

switch ($befehl) {
    case 'anlegen':
        if ($email === '') {
            abbruch('Bitte --email angeben.');
        }

        $passwort = passwortAbfragen();

        try {
            $user = $auth->register($email, $name === '' ? 'Administration' : $name, $passwort, Role::Admin);
        } catch (RegistrationException $exception) {
            abbruch($exception->getMessage());
        }

        // Ein Konto, das per Kommandozeile entsteht, hat seine Adresse nicht
        // per Link bestaetigt. Die Bestaetigung nachzuholen ist hier ehrlich:
        // Wer das Konto anlegt, ist bereits am Server.
        $verification->markEmailVerified($user->id ?? 0, $clock->now());

        printf("Verwaltungskonto angelegt: %s (#%d)\n", $user->email, $user->id ?? 0);
        echo "Anmeldung unter /anmelden, Verwaltung unter /admin/\n";

        break;

    case 'ernennen':
        if ($email === '') {
            abbruch('Bitte --email angeben.');
        }

        $user = $users->findByEmail($email);

        if ($user === null || $user->id === null) {
            abbruch(sprintf('Kein Konto zu "%s".', $email));
        }

        $rolle = trim($optionen['rolle'] ?? 'admin');

        // Die Redaktion ist keine Rolle in users.role, sondern ein Eintrag in
        // content_editors: Die Spalte traegt seit 0001 einen CHECK-Constraint,
        // und SQLite kann den nur ueber den Neubau der Tabelle aendern — fuer
        // eine Berechtigung ein zu hoher Preis. Begruendung: docs/CMS.md, E1.
        if ($rolle === 'redakteur') {
            if ($user->role === Role::Admin) {
                printf("%s ist Administration und darf die Redaktion ohnehin bedienen.\n", $user->email);

                break;
            }

            if ($editors->isEditor($user->id)) {
                printf("%s darf die Redaktion bereits bedienen.\n", $user->email);

                break;
            }

            $editors->grant($user->id, $clock->now(), null);
            printf("%s darf jetzt die Redaktion bedienen (/admin/inhalte).\n", $user->email);

            break;
        }

        if ($rolle !== 'admin') {
            abbruch(sprintf('Unbekannte Rolle "%s". Moeglich sind: admin, redakteur.', $rolle));
        }

        if ($user->role === Role::Admin) {
            printf("%s hat die Rolle admin bereits.\n", $user->email);

            break;
        }

        $verification->setRole($user->id, Role::Admin);
        printf("%s hat jetzt die Rolle admin (vorher: %s).\n", $user->email, $user->role->value);

        break;

    case 'entziehen':
        if ($email === '') {
            abbruch('Bitte --email angeben.');
        }

        $user = $users->findByEmail($email);

        if ($user === null || $user->id === null) {
            abbruch(sprintf('Kein Konto zu "%s".', $email));
        }

        if (!$editors->isEditor($user->id)) {
            printf("%s steht nicht in der Redaktion.\n", $user->email);

            break;
        }

        $editors->revoke($user->id);
        printf("%s darf die Redaktion nicht mehr bedienen.\n", $user->email);

        break;

    case 'passwort':
        if ($email === '') {
            abbruch('Bitte --email angeben.');
        }

        $user = $users->findByEmail($email);

        if ($user === null || $user->id === null) {
            abbruch(sprintf('Kein Konto zu "%s".', $email));
        }

        $passwort = passwortAbfragen();

        if (mb_strlen($passwort) < PasswordHasher::MIN_LENGTH) {
            abbruch(sprintf('Das Passwort braucht mindestens %d Zeichen.', PasswordHasher::MIN_LENGTH));
        }

        $users->updatePasswordHash($user->id, $hasher->hash($passwort));

        // Offene Sitzungen und Rücksetz-Token gehören mit weg: Wer das Passwort
        // tauscht, tut das oft, weil das alte in falsche Hände geraten ist.
        $database->execute('DELETE FROM sessions WHERE user_id = :id', ['id' => $user->id]);
        $database->execute('DELETE FROM user_tokens WHERE user_id = :id', ['id' => $user->id]);
        $verification->clearLoginFailures($user->id);

        printf("Passwort für %s gesetzt. Offene Sitzungen wurden beendet.\n", $user->email);

        break;

    case 'liste':
        $zeilen = $database->select(
            "SELECT id, email, display_name, role, status, last_login_at
               FROM users WHERE role IN ('admin','moderator') ORDER BY role, id",
        );

        if ($zeilen === []) {
            echo "Es gibt noch kein Verwaltungskonto. Anlegen mit:\n";
            echo "  php bin/admin.php anlegen --email=du@example.tld\n";

            break;
        }

        printf("%-4s %-34s %-22s %-10s %-9s %s\n", 'ID', 'E-Mail', 'Name', 'Rolle', 'Status', 'Letzte Anmeldung');

        foreach ($zeilen as $zeile) {
            printf(
                "%-4d %-34s %-22s %-10s %-9s %s\n",
                (int) $zeile['id'],
                (string) $zeile['email'],
                (string) $zeile['display_name'],
                (string) $zeile['role'],
                (string) $zeile['status'],
                is_string($zeile['last_login_at']) ? $zeile['last_login_at'] : 'nie',
            );
        }

        $redaktion = $editors->all();

        if ($redaktion !== []) {
            echo "\nRedaktion (content_editors)\n";
            printf("%-4s %-34s %-22s %s\n", 'ID', 'E-Mail', 'Name', 'Seit');

            foreach ($redaktion as $eintrag) {
                printf(
                    "%-4d %-34s %-22s %s\n",
                    $eintrag['user_id'],
                    $eintrag['email'],
                    $eintrag['display_name'],
                    $eintrag['granted_at'],
                );
            }
        }

        break;

    default:
        echo "Verwaltungskonten\n\n";
        echo "  php bin/admin.php anlegen --email=du@example.tld [--name=\"Vorname Nachname\"]\n";
        echo "  php bin/admin.php ernennen --email=vorhandenes@konto.tld [--rolle=admin|redakteur]\n";
        echo "  php bin/admin.php entziehen --email=vorhandenes@konto.tld\n";
        echo "  php bin/admin.php passwort --email=du@example.tld\n";
        echo "  php bin/admin.php liste\n";

        if ($befehl !== 'hilfe') {
            exit(1);
        }
}

/**
 * Liest --schluessel=wert und --schluessel wert.
 *
 * @param list<string> $argumente
 *
 * @return array<string, string>
 */
function optionenLesen(array $argumente): array
{
    $optionen = [];
    $anzahl = count($argumente);

    for ($i = 0; $i < $anzahl; ++$i) {
        $argument = $argumente[$i];

        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $rest = substr($argument, 2);
        $stelle = strpos($rest, '=');

        if ($stelle !== false) {
            $optionen[substr($rest, 0, $stelle)] = substr($rest, $stelle + 1);

            continue;
        }

        $folgt = $argumente[$i + 1] ?? '';
        $optionen[$rest] = str_starts_with($folgt, '--') ? '' : $folgt;
    }

    return $optionen;
}

/**
 * Fragt das Passwort ohne Echo ab und laesst es zweimal eingeben.
 */
function passwortAbfragen(): string
{
    $erstes = geheimAbfragen('Passwort: ');
    $zweites = geheimAbfragen('Wiederholen: ');

    if ($erstes !== $zweites) {
        abbruch('Die Eingaben stimmen nicht überein.');
    }

    if ($erstes === '') {
        abbruch('Kein Passwort eingegeben.');
    }

    return $erstes;
}

function geheimAbfragen(string $frage): string
{
    echo $frage;

    // Ohne Terminal (etwa in einer Pipeline) bleibt nur die normale Eingabe.
    // Das ist sichtbar, aber besser als eine Abfrage, die nichts entgegennimmt.
    $stty = shell_exec('command -v stty 2>/dev/null');
    $verstecken = is_string($stty) && trim($stty) !== '' && stream_isatty(\STDIN);

    if ($verstecken) {
        $vorher = shell_exec('stty -g 2>/dev/null');
        shell_exec('stty -echo 2>/dev/null');
    }

    $eingabe = fgets(\STDIN);

    if ($verstecken) {
        shell_exec(sprintf('stty %s 2>/dev/null', is_string($vorher) ? trim($vorher) : 'echo'));
        echo "\n";
    }

    return is_string($eingabe) ? trim($eingabe) : '';
}

function abbruch(string $meldung): never
{
    fwrite(\STDERR, $meldung . "\n");

    exit(1);
}
