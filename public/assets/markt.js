/*
 * Filterwechsel ohne Seitenneuaufbau.
 *
 * Die Seite funktioniert vollstaendig ohne dieses Skript: Alle Facetten sind
 * echte Links, alle Eingaben echte Formulare. Das Skript faengt die Navigation
 * nur ab, holt dieselbe URL erneut und tauscht die Trefferliste aus —
 * gerendert wird weiterhin auf dem Server.
 */
(function () {
    'use strict';

    var wurzel = document.querySelector('[data-markt]');
    if (!wurzel || !window.history || !window.fetch) {
        return;
    }

    var laeuft = null;

    function istInterneMarktUrl(url) {
        return url.origin === window.location.origin && url.pathname.indexOf('/markt/') === 0;
    }

    function austauschen(html, url) {
        var geparst = new DOMParser().parseFromString(html, 'text/html');
        var neu = geparst.querySelector('[data-markt]');
        if (!neu) {
            window.location.assign(url.href);
            return;
        }

        function anwenden() {
            wurzel.replaceWith(neu);
            wurzel = neu;

            var titel = geparst.querySelector('title');
            if (titel) {
                document.title = titel.textContent;
            }
        }

        // uebergang.js blendet den Austausch ueber, wenn der Browser die View
        // Transitions API kennt. Fehlt die Datei — oder wurde sie bewusst
        // weggelassen —, wird direkt getauscht. Der Filterwechsel darf von
        // dieser Zugabe nicht abhaengen.
        if (typeof window.reptilienUebergang === 'function') {
            window.reptilienUebergang(anwenden);
        } else {
            anwenden();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function laden(url, verlaufSchreiben) {
        if (laeuft) {
            laeuft.abort();
        }

        laeuft = new AbortController();
        wurzel.setAttribute('aria-busy', 'true');

        fetch(url.href, { signal: laeuft.signal, headers: { 'x-requested-with': 'fetch' } })
            .then(function (antwort) {
                if (!antwort.ok) {
                    throw new Error('HTTP ' + antwort.status);
                }
                return antwort.text();
            })
            .then(function (html) {
                if (verlaufSchreiben) {
                    window.history.pushState({ markt: true }, '', url.href);
                }
                austauschen(html, url);
            })
            .catch(function (fehler) {
                if (fehler.name !== 'AbortError') {
                    window.location.assign(url.href);
                }
            })
            .finally(function () {
                laeuft = null;
                if (wurzel) {
                    wurzel.removeAttribute('aria-busy');
                }
            });
    }

    document.addEventListener('click', function (ereignis) {
        if (ereignis.defaultPrevented || ereignis.button !== 0 || ereignis.metaKey || ereignis.ctrlKey || ereignis.shiftKey) {
            return;
        }

        var link = ereignis.target.closest('a[href]');
        if (!link || !wurzel.contains(link)) {
            return;
        }

        var url = new URL(link.href, window.location.href);
        if (!istInterneMarktUrl(url)) {
            return;
        }

        ereignis.preventDefault();
        laden(url, true);
    });

    document.addEventListener('submit', function (ereignis) {
        var formular = ereignis.target;
        if (!wurzel.contains(formular) || formular.method.toLowerCase() !== 'get') {
            return;
        }

        var url = new URL(formular.action, window.location.href);
        if (!istInterneMarktUrl(url)) {
            return;
        }

        var daten = new FormData(formular);
        url.search = new URLSearchParams(
            Array.from(daten.entries()).filter(function (eintrag) {
                return String(eintrag[1]).trim() !== '';
            })
        ).toString();

        ereignis.preventDefault();
        laden(url, true);
    });

    window.addEventListener('popstate', function () {
        laden(new URL(window.location.href), false);
    });

    // Facetten auf dem Telefon auf- und zuklappen. Auf breiten Bildschirmen
    // stehen sie ohnehin offen (lg:block), der Schalter ist dort ausgeblendet.
    // Frueher haben das Alpine-Ausdruecke im Markup erledigt — die werden zur
    // Laufzeit aus Zeichenketten gebaut und brauchen 'unsafe-eval' in der
    // Content-Security-Policy.
    document.addEventListener('click', function (ereignis) {
        var schalter = ereignis.target.closest('[data-facetten-schalter]');

        if (schalter === null) {
            return;
        }

        var bereich = schalter.closest('[data-facetten]');
        var inhalt = bereich === null ? null : bereich.querySelector('[data-facetten-inhalt]');

        if (inhalt === null) {
            return;
        }

        var offen = inhalt.classList.toggle('hidden') === false;
        schalter.setAttribute('aria-expanded', offen ? 'true' : 'false');
        schalter.textContent = offen ? 'Schließen' : 'Anzeigen';
    });
})();
