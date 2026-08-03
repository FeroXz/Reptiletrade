/*
 * Assistent: Art-Autocomplete, Zwischenspeichern und Bildverkleinerung.
 *
 * Alles davon ist Beiwerk. Ohne JavaScript funktioniert der Assistent
 * unveraendert: Die Art laesst sich ueber die Vorschlagsliste waehlen, jeder
 * Schritt hat einen Speichern-Knopf, und der Server verkleinert Bilder ohnehin.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------- Autocomplete
    var sucheFeld = document.querySelector('[data-art-autocomplete]');
    var idFeld = document.querySelector('[data-art-id]');
    var liste = document.getElementById('artenliste');

    if (sucheFeld && idFeld && liste) {
        var gefunden = {};
        var zeitgeber = null;

        sucheFeld.addEventListener('input', function () {
            var begriff = sucheFeld.value.trim();

            if (gefunden[begriff]) {
                idFeld.value = gefunden[begriff];
                return;
            }

            idFeld.value = '';
            if (begriff.length < 2) {
                return;
            }

            window.clearTimeout(zeitgeber);
            zeitgeber = window.setTimeout(function () {
                fetch('/api/v1/arten?q=' + encodeURIComponent(begriff))
                    .then(function (a) { return a.json(); })
                    .then(function (daten) {
                        liste.innerHTML = '';
                        (daten.arten || []).forEach(function (art) {
                            gefunden[art.deutsch] = String(art.id);
                            var option = document.createElement('option');
                            option.value = art.deutsch;
                            option.label = art.wissenschaftlich;
                            liste.appendChild(option);
                        });

                        if (gefunden[sucheFeld.value.trim()]) {
                            idFeld.value = gefunden[sucheFeld.value.trim()];
                        }
                    })
                    .catch(function () { /* Ohne Vorschlaege tippt man den Namen eben aus. */ });
            }, 180);
        });
    }

    // ------------------------------------------------------------- Autosave
    var formular = document.querySelector('form[data-autosave]');
    var status = document.querySelector('[data-autosave-status]');

    if (formular) {
        var wartet = null;

        var speichern = function () {
            var daten = new FormData(formular);

            fetch(formular.getAttribute('data-autosave'), { method: 'POST', body: daten })
                .then(function (antwort) { return antwort.ok ? antwort.json() : Promise.reject(antwort.status); })
                .then(function (ergebnis) {
                    if (status) {
                        status.textContent = 'Entwurf gespeichert';
                        window.setTimeout(function () { status.textContent = ''; }, 2500);
                    }
                    var anzeige = document.querySelector('[data-morph-string]');
                    if (anzeige && ergebnis.morph_string) {
                        anzeige.textContent = ergebnis.morph_string;
                    }
                })
                .catch(function () {
                    if (status) { status.textContent = 'Nicht gespeichert'; }
                });
        };

        formular.addEventListener('change', function () {
            window.clearTimeout(wartet);
            wartet = window.setTimeout(speichern, 900);
        });
    }

    // ------------------------------------------------------------- Bildverkleinerung
    var bildFormular = document.querySelector('[data-bild-upload]');

    if (bildFormular && window.FileReader && window.HTMLCanvasElement) {
        bildFormular.addEventListener('submit', function (ereignis) {
            var eingabe = bildFormular.querySelector('input[type="file"]');
            var datei = eingabe && eingabe.files ? eingabe.files[0] : null;

            if (!datei || datei.size < 1024 * 1024 || bildFormular.dataset.verkleinert === '1') {
                return;
            }

            ereignis.preventDefault();

            var bild = new Image();
            bild.onload = function () {
                var kante = 1600;
                var faktor = Math.min(1, kante / Math.max(bild.width, bild.height));
                var leinwand = document.createElement('canvas');
                leinwand.width = Math.round(bild.width * faktor);
                leinwand.height = Math.round(bild.height * faktor);
                leinwand.getContext('2d').drawImage(bild, 0, 0, leinwand.width, leinwand.height);

                leinwand.toBlob(function (blob) {
                    if (!blob) {
                        bildFormular.dataset.verkleinert = '1';
                        bildFormular.submit();
                        return;
                    }

                    var uebertragung = new DataTransfer();
                    uebertragung.items.add(new File([blob], 'bild.webp', { type: 'image/webp' }));
                    eingabe.files = uebertragung.files;
                    bildFormular.dataset.verkleinert = '1';
                    bildFormular.submit();
                }, 'image/webp', 0.85);
            };

            bild.onerror = function () {
                bildFormular.dataset.verkleinert = '1';
                bildFormular.submit();
            };

            bild.src = URL.createObjectURL(datei);
        });
    }
})();
