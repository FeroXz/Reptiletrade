# Installation

Vollständige Anleitung für einen Debian-/LAMP-Server. Kein Docker nötig, keine
Fremddienste zur Laufzeit.

Wer nur lokal entwickeln will, findet den Kurzweg unter
[„Lokal entwickeln“](#lokal-entwickeln) am Ende.

---

## 1. Voraussetzungen

| Was | Mindestens | Prüfen mit |
|---|---|---|
| PHP | 8.3 (CLI **und** Webserver) | `php -v` |
| PHP-Erweiterungen | `pdo_sqlite`, `mbstring`, `json`, `zlib`, `gd` | `php -m` |
| SQLite | 3.35 mit FTS5, `json_valid`, Mathematikfunktionen | siehe unten |
| Composer | 2.x | `composer -V` |
| Webserver | Apache 2.4 mit `mod_rewrite` **oder** nginx mit PHP-FPM | |

Node wird **nur zur Entwicklungszeit** gebraucht. Das gebaute CSS liegt fertig
unter `public/assets/` im Repository; auf dem Server muss nichts gebaut werden.

Paketinstallation auf Debian 12:

```bash
sudo apt install php8.3-cli php8.3-fpm php8.3-sqlite3 php8.3-mbstring \
                 php8.3-gd php8.3-curl unzip composer
```

`php8.3-gd` ist Pflicht, nicht optional: Ohne GD lassen sich keine Bilder
verkleinern, keine EXIF-Daten entfernen und keine WebP-Dateien schreiben. Der
Bild-Upload scheitert dann bei jedem Versuch.

**SQLite-Fähigkeiten prüfen.** Die Anwendung braucht drei Dinge, die nicht in
jedem Build stecken:

```bash
php -r '$p=new PDO("sqlite::memory:");
echo "SQLite ", $p->query("select sqlite_version()")->fetchColumn(), "\n";
foreach ([["FTS5","create virtual table t using fts5(a)"],
          ["JSON1","select json_valid(\"{}\")"],
          ["Mathematik","select cos(1)"]] as [$name,$sql]) {
    try { $p->exec($sql); echo "  $name: ok\n"; }
    catch (Throwable $e) { echo "  $name: FEHLT\n"; }
}'
```

Alle drei müssen `ok` melden. FTS5 trägt die Volltextsuche, JSON1 die
Prüfregeln der Migrationen, die Mathematikfunktionen die Haversine-Distanz der
Umkreissuche. Fehlt eines, hilft nur ein anderes PHP-Paket — nachrüsten lässt
sich das nicht zur Laufzeit.

---

## 2. Dateien ablegen

Die Anwendung gehört **außerhalb** des Web-Verzeichnisses:

```bash
sudo mkdir -p /var/www/reptilienmarkt
sudo chown $USER:www-data /var/www/reptilienmarkt
git clone <repository-url> /var/www/reptilienmarkt
cd /var/www/reptilienmarkt
composer install --no-dev --optimize-autoloader
```

`--no-dev` lässt PHPUnit, PHPStan und PHP-CS-Fixer weg — auf einem Server haben
sie nichts zu suchen.

### Wohin die Domain zeigen muss

> **Der Document Root ist `/var/www/reptilienmarkt/public` — und nur dieses
> Verzeichnis.**

Nicht `/var/www/reptilienmarkt`. Zeigt die Domain eine Ebene zu hoch, sind
`.env` mit allen Zugangsdaten, `storage/db/reptilienmarkt.sqlite` mit allen
Nutzerdaten und `storage/private/` mit allen Rechtsnachweisen über den Browser
abrufbar. Das ist kein theoretischer Fehler: Es ist der häufigste Weg, auf dem
eine PHP-Anwendung ihre Datenbank verliert.

So sieht die Ablage aus:

```
/var/www/reptilienmarkt/          ← Projektwurzel, NICHT öffentlich
├── bin/                          CLI-Befehle
├── config/                       .env-Laden, Container, Routen
├── public/                       ← HIER zeigt die Domain hin
│   ├── index.php                 Front-Controller
│   ├── .htaccess                 Rewrite-Regeln (Apache)
│   ├── assets/                   CSS und JS
│   └── uploads/                  Anzeigenbilder (öffentlich, gewollt)
├── storage/
│   ├── db/                       SQLite-Datei
│   ├── private/                  Rechtsnachweise (NIE öffentlich)
│   ├── logs/                     JSON-Protokolle
│   └── backups/                  Sicherungen
├── src/                          Anwendungscode
└── .env                          Konfiguration mit Zugangsdaten
```

### Rechte

```bash
cd /var/www/reptilienmarkt
sudo chown -R $USER:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;

# Nur diese vier Verzeichnisse beschreibt der Webserver:
sudo chmod -R 770 storage/db storage/private storage/logs public/uploads
sudo chmod 750 bin/*.php
```

Die SQLite-Datei braucht Schreibrechte **auf das Verzeichnis**, nicht nur auf
die Datei: SQLite legt im WAL-Modus daneben `…-wal` und `…-shm` an. Ein
schreibgeschütztes `storage/db/` ergibt den Fehler
`attempt to write a readonly database`, obwohl die Datei selbst beschreibbar
ist.

---

## 3. Konfiguration

```bash
cp .env.example .env
chmod 600 .env
```

Dann `.env` bearbeiten. Die Werte, die auf einem echten Server wirklich anders
sein müssen:

```dotenv
APP_ENV=production
APP_DEBUG=0                       # NIE 1 auf einem öffentlichen Server
APP_URL=https://deine-domain.tld  # ohne Schrägstrich am Ende
APP_TIMEZONE=Europe/Berlin

DB_DRIVER=sqlite
DB_DATABASE=storage/db/reptilienmarkt.sqlite

STORAGE_PUBLIC=public/uploads
STORAGE_PRIVATE=storage/private

MAIL_TRANSPORT=sendmail           # "datei" verschickt nichts
MAIL_FROM=noreply@deine-domain.tld
MAIL_FROM_NAME=Reptilienmarkt

LOG_DIRECTORY=storage/logs
LOG_LEVEL=info
BACKUP_DIRECTORY=storage/backups
BACKUP_KEEP=14
```

`APP_URL` steht in jeder verschickten Mail und in jedem Bestätigungslink. Steht
dort noch `https://example.tld`, laufen alle Links ins Leere — und niemand kann
seine E-Mail-Adresse bestätigen oder ein Passwort zurücksetzen.

`APP_DEBUG=1` zeigt bei jedem Fehler Dateipfade und Programmzeilen im Browser.
Auf einem öffentlichen Server ist das eine Einladung.

### Pflichtangaben: `config/impressum.php`

Eine deutsche Seite braucht ein Impressum (§ 5 DDG) und eine Datenschutz-
erklärung (Art. 13 DSGVO), erreichbar mit höchstens zwei Klicks. Beides steht
im Fuß jeder Seite unter `/impressum` und `/datenschutz` — gespeist aus einer
einzigen Datei:

```bash
$EDITOR config/impressum.php
```

Ausgeliefert wird sie mit Platzhaltern. Solange die drinstehen, zeigt **jede**
Rechtsseite einen sichtbaren Warnhinweis samt Liste der fehlenden Angaben,
statt wie ein fertiges Impressum auszusehen. Ein unvollständiges Impressum, das
vollständig aussieht, fällt niemandem auf — deshalb dieser Umweg.

Auszufüllen sind mindestens:

| Feld | Warum |
| --- | --- |
| `anbieter.name` | Voller Name bzw. Firma samt Rechtsform (§ 5 Abs. 1 Nr. 1 DDG) |
| `anbieter.strasse`, `plz`, `ort` | Ladungsfähige Anschrift — ein Postfach genügt nicht |
| `kontakt.email` | Pflicht; dazu ein zweiter Weg für unmittelbare Kommunikation |
| `hosting.anbieter` | Steht in der Datenschutzerklärung |
| `unvollstaendig` | Nach dem Ausfüllen auf `false` setzen |

Je nach Tätigkeit kommen `register`, `umsatzsteuer_id` (§ 27a UStG — die
Steuernummer gehört **nicht** ins Impressum), `inhaltlich_verantwortlich`
(§ 18 Abs. 2 MStV) und `aufsichtsbehoerde` hinzu. Die Datei ist durchkommentiert.

```bash
php bin/doctor.php     # meldet jede fehlende Pflichtangabe namentlich
```

Zwei Dinge, die kein Programm für dich erledigt: der Auftragsverarbeitungs-
vertrag mit dem Hoster (Art. 28 DSGVO — danach `hosting.avv_geschlossen` auf
`true`), und eine anwaltliche Prüfung vor der Freischaltung. Die Hinweise hier
sind eine Orientierung, keine Rechtsberatung.

---

## 4. Datenbank aufsetzen

```bash
php bin/migrate.php up
php bin/seed.php
```

`bin/seed.php` legt Artenstamm, Merkmalskatalog, Postleitzahlen, Rechtstexte und
Betriebsschalter an. Der Aufruf ist wiederholbar (Upsert) und dauert unter einer
Sekunde.

Prüfen:

```bash
php bin/migrate.php status     # alle Migrationen "angewandt"
```

Die Postleitzahlen kommen als Näherung mit. Wer exakte Zentroide will, lädt
`DE.txt` bei GeoNames und ersetzt sie:

```bash
php bin/import_postal_codes.php --geonames=/pfad/zu/DE.txt
```

---

## 5. Webserver

### Apache

```apache
<VirtualHost *:443>
    ServerName deine-domain.tld
    DocumentRoot /var/www/reptilienmarkt/public

    <Directory /var/www/reptilienmarkt/public>
        # Ohne diese Zeile wird public/.htaccess ignoriert und jede
        # Unterseite antwortet 404.
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Der Rest des Projekts bleibt draußen.
    <Directory /var/www/reptilienmarkt>
        Require all denied
    </Directory>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/deine-domain.tld/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/deine-domain.tld/privkey.pem

    ErrorLog  ${APACHE_LOG_DIR}/reptilienmarkt-error.log
    CustomLog ${APACHE_LOG_DIR}/reptilienmarkt-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers expires
sudo a2ensite reptilienmarkt
sudo systemctl reload apache2
```

Wer `AllowOverride All` nicht setzen will, trägt die Regeln aus
`public/.htaccess` direkt in den `<Directory>`-Block ein — das ist sogar
schneller, weil Apache dann nicht bei jedem Aufruf nach `.htaccess` sucht.

### nginx mit PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name deine-domain.tld;
    root /var/www/reptilienmarkt/public;   # nur public/, nichts darüber
    index index.php;

    client_max_body_size 24M;              # Bilder und PDF-Nachweise

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Hochgeladene Bilder werden nie ausgeführt.
    location ^~ /uploads/ {
        location ~ \.php$ { return 403; }
    }

    # Dasselbe für die Mediathek der Redaktion.
    location ^~ /media/ {
        location ~ \.php$ { return 403; }
        expires 30d;                       # eigener Dateiname je Bild, also unveränderlich
        add_header Cache-Control "public, immutable";
    }

    location ~ /\. { deny all; }

    ssl_certificate     /etc/letsencrypt/live/deine-domain.tld/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/deine-domain.tld/privkey.pem;
}
```

Die Reihenfolge zählt: Die `uploads`- und `media`-Blöcke müssen **vor** dem
allgemeinen `\.php$`-Block greifen, sonst führt PHP-FPM eine als Bild
hochgeladene Skriptdatei doch noch aus. Das `^~` sorgt genau dafür.

Bei Apache übernehmen das `public/uploads/.htaccess` und `public/media/.htaccess`
— vorausgesetzt, der VirtualHost erlaubt `AllowOverride All`. Ob die Sperre
tatsächlich greift, prüft `php bin/doctor.php`.

Das Verzeichnis `public/media/` muss für den Webserver-Benutzer beschreibbar
sein — dort legt die Redaktion ihre Bilder ab, nach Jahr und Monat gegliedert:

```bash
sudo install -d -o www-data -g www-data -m 775 /var/www/reptilienmarkt/public/media
```

---

## 6. Administratorkonto

**Es gibt kein voreingestelltes Administratorkonto und keinen
Einrichtungsassistenten im Browser.** Ein mitgeliefertes Standardpasswort wird
vergessen, und eine offene Einrichtungsseite ist so lange eine offene Tür, wie
sie niemand schließt. Der erste Administrator entsteht auf dem Server:

```bash
php bin/admin.php anlegen --email=du@deine-domain.tld --name="Vorname Nachname"
```

Das Passwort wird abgefragt, nicht als Argument übergeben — Argumente stehen in
der Shell-Historie und in der Prozessliste. Es braucht mindestens zehn Zeichen.

Danach unter `https://deine-domain.tld/anmelden` anmelden; die Verwaltung liegt
unter `/admin/`, und im Kopf der Seite erscheint der Punkt „Verwaltung“.

Weitere Befehle:

```bash
php bin/doctor.php                                   # Selbsttest der Installation
php bin/admin.php liste                              # alle Admins und Moderatoren
php bin/admin.php ernennen --email=vorhandenes@konto  # bestehendes Konto befördern
php bin/admin.php passwort --email=du@deine-domain.tld  # Passwort neu setzen
```

`passwort` beendet zugleich alle offenen Sitzungen dieses Kontos und wirft
ausstehende Rücksetz-Token weg — wer das Passwort tauscht, tut das oft, weil das
alte in falsche Hände geraten ist.

> **Admin-Zugang verloren?** Es gibt keine Hintertür und keinen Wiederherstell-
> Code. Wer auf den Server kommt, setzt das Passwort mit `bin/admin.php
> passwort` neu. Wer nicht auf den Server kommt, kommt auch nicht an die
> Verwaltung — das ist Absicht.

Ruft ein Konto ohne Adminrolle `/admin/` auf, bekommt es **404, nicht 403**. Die
Verwaltung muss sich nicht dadurch verraten, dass sie einen Zugriff ablehnt. Wer
also „404“ sieht, obwohl er Administrator sein sollte, hat die Rolle nicht —
`php bin/admin.php liste` zeigt es.

### Konten sperren und löschen

Unter `/admin/nutzer` lassen sich Konten suchen, sperren und löschen. Eine
Sperre verlangt immer einen Grund; der Gesperrte bekommt ihn bei der nächsten
Anmeldung zu lesen, samt Enddatum und Verweis auf `/kontakt`. Voreingestellt
ist eine Frist (3 bis 90 Tage) — sie läuft stündlich von selbst ab, ohne dass
jemand daran denken muss. Unbefristet geht auch, ist aber eine eigene Auswahl.

Eine Sperre beendet alle offenen Sitzungen des Kontos und pausiert seine
Anzeigen; beim Entsperren laufen genau diese wieder an. Anzeigen, die der
Anbieter selbst pausiert hatte, bleiben pausiert.

Löschen folgt derselben Abwägung wie die Selbstlöschung: Gibt es Bewertungen,
wird anonymisiert statt gelöscht — sie gehören auch der Gegenseite. Ein Konto
mit Verwaltungsrechten lässt sich weder sperren noch löschen, solange es die
Rolle hat; das eigene erst recht nicht.

Anfragen aus dem Kontaktformular stehen unter `/admin/kontakt`. Sie gehen
zusätzlich an die im Impressum hinterlegte E-Mail-Adresse — fehlt die, bleiben
sie trotzdem in der Warteschlange stehen.

---

## 7. Cron-Einträge

Ohne diese drei Zeilen laufen Anzeigen nie ab, es gehen keine Erinnerungen
hinaus, Aufbewahrungsfristen greifen nicht und es gibt keine Sicherung:

```bash
sudo crontab -u www-data -e
```

```cron
*/15 * * * * cd /var/www/reptilienmarkt && php bin/cron.php   >> storage/logs/cron.out 2>&1
*/5 * * * *  cd /var/www/reptilienmarkt && php bin/worker.php --einmal >> storage/logs/cron.out 2>&1
30  2 * * *  cd /var/www/reptilienmarkt && php bin/backup.php >> storage/logs/cron.out 2>&1
```

`cron.php` plant ein, was fällig ist; `worker.php` arbeitet ab. Der Zeitplan
selbst steht im Code (`Reptilienmarkt\Domain\Job\JobScheduler`), nicht in der
Crontab — `php bin/cron.php plan` zeigt ihn.

Der Aufruf ist viertelstündlich, nicht stündlich: Ein geplanter Beitrag soll
nicht bis zu einer Stunde zu spät erscheinen. Die stündlichen und täglichen
Aufgaben laufen deswegen nicht öfter — `JobScheduler` kennt zu jedem Auftrag
seinen Abstand und plant ihn erst wieder ein, wenn er verstrichen ist.

Wichtig ist der Benutzer: Läuft der Cron als `root`, gehören die neu
geschriebenen Dateien danach `root`, und der Webserver kann die Datenbank nicht
mehr beschreiben. Deshalb `crontab -u www-data`.

---

## 8. Abnahme

Ein Befehl prüft die ganze Installation:

```bash
php bin/doctor.php
```

Er geht PHP-Erweiterungen, SQLite-Fähigkeiten, `.env`, Schreibrechte, Datenbank,
Sitzungen und den Auftragsstand durch und nennt zu jedem Fund den nächsten
Schritt. Rückgabewert `0` heißt sauber, `1` heißt: mindestens ein echter Fehler.

Danach von Hand:

```bash
php bin/migrate.php status        # alles angewandt
php bin/admin.php liste           # mindestens ein Admin
php bin/cron.php plan             # neun Aufgaben
php bin/worker.php --einmal       # läuft ohne Fehler durch
php bin/backup.php                # schreibt eine Sicherung
```

Dann im Browser: Startseite, `/markt/`, eine Detailseite, `/anmelden`, `/admin/`.
Wenn eine Unterseite 404 liefert, während die Startseite läuft, fehlt die
Rewrite-Regel — siehe unten.

---

## 9. Typische Fehler und ihre Ursache

### Jedes Formular meldet „Das Formular ist abgelaufen“

Registrierung, Anmeldung, Nachricht — alles endet mit `400`. Das heißt fast nie,
dass das Formular wirklich zu lange offen lag: Der Browser schickt das
Sitzungs-Cookie nicht mit, also findet der Server keinen CSRF-Token.

Die Meldung unterscheidet die beiden Fälle. Steht dort „Deine Sitzung ist nicht
angekommen“, fehlt das Cookie; steht dort „Das Formular ist abgelaufen“, war
wirklich ein alter Token im Spiel.

Prüfen:

```bash
php bin/doctor.php                        # Sitzungen schreib-/lesbar?
grep -c csrf.no_session storage/logs/*.log   # kommt das Cookie nie an?
```

Mögliche Ursachen:

1. **Ein vorgelagerter Zwischenspeicher** (Varnish, nginx `proxy_cache`, eine
   aggressive CDN-Regel) legt die HTML-Seite ab und liefert allen Besuchern
   denselben Token, ohne `Set-Cookie`. Die Anwendung schickt auf jeder
   HTML-Antwort `Cache-Control: private, no-store` — dieser Kopf darf unterwegs
   nicht überschrieben werden. HTML gehört hier in keinen gemeinsamen Cache.
2. **Cookies im Browser blockiert**, etwa in einem strengen privaten Fenster.
   Das trifft nur einzelne Besucher, nicht alle.
3. **Uhrzeit des Servers falsch** — läuft sie mehr als einen Tag vor, ist jede
   neue Sitzung sofort abgelaufen: `timedatectl` prüfen.

> Bis Version vom 04.08.2026 gab es hier eine vierte, häufigere Ursache: Das
> `Secure`-Flag des Cookies wurde aus `APP_URL` abgeleitet. Stand dort `https`,
> lief die Seite aber über `http`, verwarf der Browser das Cookie stillschweigend
> — und **kein einziges Formular** kam je durch. Das Flag hängt jetzt an der
> tatsächlichen Verbindung. Hinter einem TLS-Proxy setzt es die Anwendung, wenn
> der Proxy `X-Forwarded-Proto: https` mitschickt; fehlt der Kopf, läuft die
> Seite trotzdem, nur ohne das zusätzliche Flag.

### Startseite läuft, jede Unterseite meldet 404

Die Rewrite-Regel greift nicht. Bei Apache fast immer `AllowOverride None` im
VirtualHost: `public/.htaccess` wird dann kommentarlos ignoriert.

```bash
apache2ctl -M | grep rewrite      # rewrite_module muss dabei sein
sudo a2enmod rewrite && sudo systemctl restart apache2
```

Und im VirtualHost `AllowOverride All` setzen. Bei nginx fehlt die Zeile
`try_files $uri $uri/ /index.php$is_args$args;`.

### Der Browser zeigt PHP-Quelltext statt der Seite

PHP ist nicht als Handler eingebunden. Bei nginx fehlt der `location ~ \.php$`-
Block oder der `fastcgi_pass`-Pfad stimmt nicht (Version im Socketnamen prüfen:
`ls /run/php/`). Bei Apache fehlt `php8.3-fpm` mitsamt `a2enconf php8.3-fpm`.

### `SQLSTATE[HY000] [14] unable to open database file`

Der Webserver darf `storage/db/` nicht lesen oder nicht schreiben — oder
`DB_DATABASE` zeigt ins Leere. Der Pfad in `.env` ist **relativ zur
Projektwurzel**, nicht zu `public/`.

```bash
sudo chown -R $USER:www-data storage/db
sudo chmod 770 storage/db
sudo -u www-data php bin/migrate.php status   # als Webserver-Benutzer prüfen
```

### `attempt to write a readonly database`

Die Datei ist beschreibbar, das **Verzeichnis** nicht. SQLite legt im WAL-Modus
`…-wal` und `…-shm` daneben an und braucht dafür Schreibrechte auf den Ordner:
`sudo chmod 770 storage/db`.

Zweite Möglichkeit: Ein Cron-Lauf als `root` hat die Dateien übernommen.
`ls -l storage/db/` zeigt es, `sudo chown -R $USER:www-data storage/db` behebt
es — und der Cron gehört dann `www-data`.

### `.env` ist im Browser erreichbar

Der Document Root zeigt auf die Projektwurzel statt auf `public/`. **Sofort
handeln:** Document Root korrigieren, Webserver neu starten, danach alle
Geheimnisse in `.env` austauschen und alle Sitzungen verwerfen
(`DELETE FROM sessions;`). Was einmal öffentlich war, gilt als bekannt.

### Bild-Upload schlägt immer fehl

Meist fehlt `php8.3-gd` (`php -m | grep -i gd`). Sonst sind es die
Upload-Grenzen in `php.ini`:

```ini
upload_max_filesize = 12M
post_max_size = 24M
memory_limit = 256M
max_file_uploads = 20
```

`post_max_size` muss größer sein als `upload_max_filesize`, sonst schneidet PHP
den Upload stillschweigend ab — das Formular kommt dann leer an, ohne
Fehlermeldung. Bei nginx zusätzlich `client_max_body_size 24M;`, sonst gibt es
ein `413 Request Entity Too Large`, bevor PHP überhaupt gefragt wird.

### Keine E-Mails: keine Bestätigung, kein Passwort-Reset

`MAIL_TRANSPORT=datei` ist die Voreinstellung und **verschickt nichts** — die
Nachrichten landen als Dateien unter `storage/mail/`. Das ist zur Entwicklung
gewollt. Auf dem Server `MAIL_TRANSPORT=sendmail` setzen und einen MTA
installieren (`postfix`, `msmtp-mta`, …).

Kommen Mails an, landen aber im Spam: `MAIL_FROM` muss zu einer Domain gehören,
für die SPF und DKIM gesetzt sind.

### Bestätigungslinks zeigen auf `example.tld`

`APP_URL` steht noch auf dem Beispielwert. Die Links werden beim Verschicken
gebaut, nicht beim Klicken — bereits verschickte Mails bleiben kaputt.

### Anzeigen laufen nie ab, Erinnerungen kommen nie

Der Cron fehlt oder läuft ins Leere. Prüfen:

```bash
php bin/cron.php einplanen     # sollte Aufträge nennen
php bin/worker.php --einmal    # sollte sie abarbeiten
tail -f storage/logs/*.log
```

Steht in der Auftragsliste etwas auf `fehlgeschlagen`, zeigt das Dashboard unter
`/admin/` den Fehlertext. Gescheiterte Aufträge werden bewusst nicht
aufgeräumt — sie warten auf einen Menschen.

### Zeitangaben sind um ein bis zwei Stunden verschoben

`APP_TIMEZONE` ist nicht gesetzt oder steht auf `UTC`, während der Server auf
Ortszeit läuft. Alle Zeitstempel werden intern in UTC geschrieben und erst zur
Anzeige umgerechnet; `APP_TIMEZONE=Europe/Berlin` ist die Anzeigezeitzone.

### Umlaute erscheinen als `?` oder `Ã¤`

Fast immer die Datenbank-Kollation eines vorgelagerten Proxys oder ein
`default_charset` in der `php.ini`, das nicht `UTF-8` ist. Die Anwendung
schreibt und liefert durchgehend UTF-8.

### Die Suche findet nichts, obwohl Anzeigen da sind

Der Volltextindex ist leer oder steht auf einem alten Stand — etwa nach einem
direkten Import in die Datenbank oder einem eingespielten Backup:

```bash
php bin/reindex.php
```

### Nach einem Update sieht die Seite unformatiert aus

Der Browser hält das alte CSS. Auf dem Server wird nichts gebaut — `public/assets/`
kommt fertig aus dem Repository. Nach `git pull` genügt ein harter Reload; wer am
CSS entwickelt, baut lokal mit `npm run build` und commitet das Ergebnis mit.

### `Composer detected issues in your platform`

Die PHP-Version des CLI unterscheidet sich von der des Webservers, oder eine
Erweiterung fehlt. `php -v` und `php -i | grep 'Loaded Configuration'` zeigen,
welches PHP der CLI benutzt — bei mehreren Versionen hilft
`update-alternatives --config php`.

---

## 10. Aktualisieren

```bash
cd /var/www/reptilienmarkt
php bin/backup.php                       # zuerst sichern
git pull
composer install --no-dev --optimize-autoloader
php bin/migrate.php up
php bin/seed.php                         # wiederholbar, aktualisiert Stammdaten
```

Migrationen laufen vorwärts und rückwärts. `php bin/migrate.php up --pretend`
zeigt vorher, was passieren würde.

---

## 11. Sichern und zurückspielen

Gesichert wird über `VACUUM INTO`, nicht über `cp`: Eine Dateikopie im laufenden
Betrieb erwischt die Datenbank mitten in einer Transaktion und liefert im
WAL-Modus eine Datei ohne das zugehörige Write-Ahead-Log. Die Sicherung ist dann
still unbrauchbar und fällt erst beim Zurückspielen auf — also genau dann, wenn
man sie braucht.

```bash
php bin/backup.php --ziel=/var/backups/reptilienmarkt --behalten=30
```

Mitsichern muss man außerdem `storage/private/` (Rechtsnachweise) und
`public/uploads/` (Bilder) — die liegen nicht in der Datenbank.

Zurückspielen:

```bash
sudo systemctl stop apache2
cp /var/backups/reptilienmarkt/reptilienmarkt-20260803-023000.sqlite \
   storage/db/reptilienmarkt.sqlite
rm -f storage/db/reptilienmarkt.sqlite-wal storage/db/reptilienmarkt.sqlite-shm
sudo chown $USER:www-data storage/db/reptilienmarkt.sqlite
php bin/reindex.php
sudo systemctl start apache2
```

Die `-wal`- und `-shm`-Dateien müssen weg: Sie gehören zur alten Datenbank und
machen die zurückgespielte unbrauchbar.

---

## Lokal entwickeln

```bash
git clone <repository-url> reptilienmarkt && cd reptilienmarkt
composer install
cp .env.example .env          # APP_ENV=local, APP_DEBUG=1
php bin/migrate.php up
php bin/seed.php
php bin/admin.php anlegen --email=admin@localhost.test
php -S 127.0.0.1:8000 -t public public/index.php
```

Der eingebaute Server braucht kein `.htaccess`: Der letzte Parameter
(`public/index.php`) ist der Router, der alles an den Front-Controller gibt.
Wird er weggelassen, laufen nur Startseite und Dateien.

Mails landen unter `storage/mail/` und werden nicht verschickt. Bestätigungs-
links stehen im Text der abgelegten Datei — damit lässt sich der ganze
Registrierungsweg ohne MTA durchspielen.

Qualitätstore:

```bash
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse                 # Level 8
php tools/smoke_betrieb.php                # Verwaltung, Auskunft, Betrieb
php tools/smoke_wizard.php                 # Anzeige komplett anlegen
```
