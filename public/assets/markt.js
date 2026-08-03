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

        wurzel.replaceWith(neu);
        wurzel = neu;

        var titel = geparst.querySelector('title');
        if (titel) {
            document.title = titel.textContent;
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
})();
