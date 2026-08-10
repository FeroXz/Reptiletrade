/*
 * Weiche Uebergaenge beim Filterwechsel.
 *
 * markt.js tauscht die Trefferliste gegen frisch gerendertes Markup aus. Das
 * ist ein harter Schnitt: Wo eben noch vierundzwanzig Kacheln standen, stehen
 * im naechsten Bildaufbau elf andere. Die View Transitions API laesst den
 * Browser den Zustand davor und danach ueberblenden.
 *
 * Reine Zugabe. Faellt diese Datei aus, kennt der Browser die API nicht oder
 * hat der Nutzer Bewegung abbestellt, laeuft der Austausch unveraendert
 * weiter — markt.js ruft die Aenderung dann einfach direkt auf.
 *
 * Als eigene Datei und nicht in markt.js, weil sie sich abschalten laesst,
 * ohne die Filterlogik anzufassen: Es reicht, das script-Tag zu entfernen.
 */
(function () {
    'use strict';

    // Kein Alpine, kein Bundler: Die Absprache zwischen den beiden Dateien ist
    // diese eine Funktion am window-Objekt. markt.js prueft, ob es sie gibt.
    window.reptilienUebergang = function (aenderung) {
        if (typeof aenderung !== 'function') {
            return;
        }

        // Feature-Detection statt Browsererkennung. startViewTransition fehlt
        // in Firefox und in aelteren Safari-Versionen.
        if (typeof document.startViewTransition !== 'function') {
            aenderung();
            return;
        }

        // Bei abbestellter Bewegung gar nicht erst anfangen. Die Alternative
        // waere, die Animation per CSS auf 0s zu setzen — dann laeuft der
        // Uebergang trotzdem durch alle Phasen und kann einen Bildaufbau
        // kosten, ohne dass jemand etwas davon hat.
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            aenderung();
            return;
        }

        try {
            document.startViewTransition(aenderung);
        } catch (fehler) {
            // Ein fehlgeschlagener Uebergang darf den Filterwechsel nicht
            // verschlucken — die Liste muss in jedem Fall ausgetauscht werden.
            aenderung();
        }
    };
})();
