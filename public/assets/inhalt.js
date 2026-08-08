/*
 * Verbesserungen fuer den Blockeditor.
 *
 * Alles davon ist Beiwerk. Ohne JavaScript funktioniert der Editor
 * unveraendert: Bloecke werden ueber echte Formular-Knoepfe hinzugefuegt,
 * verschoben und geloescht, jede Aktion ist ein POST mit CSRF-Token.
 *
 * Kein eval, keine Inline-Handler, kein aus Zeichenketten gebautes Markup —
 * die Content-Security-Policy bleibt unangetastet (script-src 'self').
 */
(function () {
    'use strict';

    var formular = document.getElementById('inhalt-formular');

    if (!formular) {
        return;
    }

    // ------------------------------------- Typ des neuen Blocks in den Knopf
    // Ohne Javascript liest der Server das Auswahlfeld. Mit Javascript traegt
    // der Knopf den Typ direkt — dann legt ein Doppelklick nicht zwei
    // verschiedene Bloecke an, weil sich die Auswahl dazwischen geaendert hat.
    var auswahl = document.getElementById('neuer_block');
    var hinzufuegen = document.getElementById('block-hinzufuegen');

    if (auswahl && hinzufuegen) {
        var uebernehmen = function () {
            hinzufuegen.value = auswahl.value;
        };

        uebernehmen();
        auswahl.addEventListener('change', uebernehmen);
    }

    // ------------------------------------------------ Loeschen bestaetigen
    // Der Server loescht auch ohne diese Rueckfrage; sie faengt nur den
    // Fehlgriff ab, und das ist der einzige Fall, in dem sie etwas wert ist.
    document.querySelectorAll('[data-bestaetigen]').forEach(function (knopf) {
        knopf.addEventListener('click', function (ereignis) {
            if (!window.confirm(knopf.getAttribute('data-bestaetigen'))) {
                ereignis.preventDefault();
            }
        });
    });

    // ----------------------------------------------------------- Autosave
    // Speichert den Stand still im Hintergrund. Der Speichern-Knopf bleibt der
    // verbindliche Weg — dieser hier faengt nur ab, was ein geschlossener Tab
    // sonst verschluckt.
    var ziel = formular.getAttribute('data-autosave');

    if (!ziel || !window.fetch) {
        return;
    }

    // FormData hat kein brauchbares toString — es liefert "[object FormData]",
    // und der Vergleich waere immer gleich. Also selbst zusammensetzen.
    var abbild = function (daten) {
        var teile = [];
        daten.forEach(function (wert, name) {
            teile.push(name + '=' + String(wert));
        });
        return teile.join('&');
    };

    var stand = abbild(new FormData(formular));
    var laeuft = false;

    var sichern = function () {
        if (laeuft) {
            return;
        }

        var daten = new FormData(formular);
        daten.set('aktion', 'autosave');

        var jetzt = abbild(daten);
        if (jetzt === stand) {
            return;
        }

        laeuft = true;

        fetch(ziel, { method: 'POST', body: daten, credentials: 'same-origin' })
            .then(function (antwort) {
                if (antwort.ok) {
                    stand = jetzt;
                }
            })
            .catch(function () {
                // Ein fehlgeschlagener Autosave ist keine Meldung wert: Der
                // Speichern-Knopf steht weiterhin da, und eine Fehlerblase
                // waehrend des Schreibens stoert mehr, als sie hilft.
            })
            .then(function () {
                laeuft = false;
            });
    };

    window.setInterval(sichern, 60000);

    // Beim Verlassen des Felds — dann ist ein Gedanke meist zu Ende.
    formular.addEventListener('focusout', sichern);
})();
