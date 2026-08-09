/*
 * Merkliste: tauscht den Knopf, ohne die Seite neu zu laden.
 *
 * Reines Beiwerk. Ohne dieses Skript ist das Formular ein Formular: Der Server
 * antwortet mit einer Weiterleitung auf dieselbe Seite, und der Knopf steht
 * danach richtig. Deshalb wird der Standardablauf erst unterbunden, wenn der
 * Austausch auch wirklich gelingt — scheitert die Anfrage, faellt die Seite auf
 * den gewoehnlichen Weg zurueck.
 *
 * Kein Inline-Handler: Die Content-Security-Policy laesst kein 'unsafe-inline'
 * bei script-src zu, und das soll so bleiben.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (ereignis) {
        var formular = ereignis.target.closest('[data-merken]');

        if (formular === null) {
            return;
        }

        ereignis.preventDefault();

        var knopf = formular.querySelector('button');
        var daten = new FormData(formular);

        if (knopf !== null) {
            knopf.disabled = true;
        }

        fetch(formular.getAttribute('action'), {
            method: 'POST',
            body: daten,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (antwort) {
                if (!antwort.ok) {
                    throw new Error('abgelehnt');
                }

                return antwort.json();
            })
            .then(function (ergebnis) {
                formular.setAttribute('action', ergebnis.aktion);
                formular.setAttribute('data-gemerkt', ergebnis.gemerkt ? 'ja' : 'nein');

                if (knopf !== null) {
                    knopf.disabled = false;
                    knopf.textContent = ergebnis.text;
                    knopf.setAttribute('aria-pressed', ergebnis.gemerkt ? 'true' : 'false');
                }
            })
            .catch(function () {
                // Zurueck auf den gewoehnlichen Weg: abschicken, neu laden.
                if (knopf !== null) {
                    knopf.disabled = false;
                }

                formular.submit();
            });
    });
})();
