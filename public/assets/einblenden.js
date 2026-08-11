/*
 * Eingangsanimation fuer Raster.
 *
 * Ein Container mit [data-einblenden-gruppe] uebergibt seine direkten Kinder
 * an einen IntersectionObserver. Wer in den sichtbaren Bereich kommt, wird
 * eingeblendet und danach nicht weiter beobachtet — die Bewegung gehoert zum
 * ersten Erscheinen, nicht zu jedem Vorbeiscrollen.
 *
 * Der Ausgangszustand (unsichtbar, um eine Zeile versetzt) wird hier gesetzt
 * und nicht im Stylesheet. Das ist Absicht: Faellt diese Datei aus, kennt der
 * Browser IntersectionObserver nicht oder ist Bewegung abbestellt, steht der
 * Inhalt einfach da. Eine per CSS ausgeblendete Karte, die auf JavaScript
 * wartet, waere bei jedem Fehler dauerhaft verschwunden.
 *
 * Kein Framework, kein Bundler — die Content-Security-Policy erlaubt nur
 * eigene Dateien und kein 'unsafe-eval'.
 */
(function () {
    'use strict';

    if (typeof window.IntersectionObserver !== 'function') {
        return;
    }

    // Bewegung abbestellt: gar nicht erst anfangen. Nicht verkuerzen, nicht
    // ueberblenden — es wird schlicht nichts angefasst.
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    // Nach so vielen Kacheln wiederholt sich der Versatz. Mehr Stufen hiesse,
    // dass die letzte Karte einer langen Reihe eine halbe Sekunde nachhinkt.
    var VERZUG_STUFEN = 6;

    var beobachter = new IntersectionObserver(function (eintraege) {
        eintraege.forEach(function (eintrag) {
            if (!eintrag.isIntersecting) {
                return;
            }

            eintrag.target.setAttribute('data-einblenden', 'sichtbar');
            beobachter.unobserve(eintrag.target);
        });
    }, {
        // Ein Stueck vor der Unterkante ausloesen: Wer zuegig scrollt, soll
        // fertige Karten sehen und nicht beim Aufbau zuschauen.
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.05
    });

    function vorbereiten(gruppe) {
        var kinder = gruppe.children;

        for (var i = 0; i < kinder.length; i++) {
            var kind = kinder[i];

            // Schon vorbereitet — etwa, weil eine Gruppe zweimal auftaucht.
            if (kind.hasAttribute('data-einblenden')) {
                continue;
            }

            kind.setAttribute('data-einblenden', 'bereit');
            kind.setAttribute('data-verzug', String(i % VERZUG_STUFEN));
            beobachter.observe(kind);
        }
    }

    function starten() {
        var gruppen = document.querySelectorAll('[data-einblenden-gruppe]');

        for (var i = 0; i < gruppen.length; i++) {
            vorbereiten(gruppen[i]);
        }
    }

    // Das Skript laeuft mit defer, das Dokument ist also in aller Regel
    // fertig geparst. Der zweite Zweig faengt den Fall ab, dass es doch
    // frueher drankommt.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', starten);
    } else {
        starten();
    }

    /*
     * Beim Filterwechsel tauscht markt.js die ganze Trefferliste aus. Die
     * neuen Kacheln tragen kein data-einblenden und sind damit sofort
     * sichtbar — genau richtig: Diesen Wechsel blendet bereits der View
     * Transition ueber, und zwei Animationen uebereinander waeren eine zu
     * viel.
     */
})();
