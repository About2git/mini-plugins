Köder-Labor
===========

Interaktives Lernwerkzeug zur Köderwahl auf deutsche Süßwasser-Raubfische
(Hecht, Zander, Flussbarsch, Wels, Rapfen, Bachforelle).

Verwendung
----------

Ordner `koeder-labor` nach `wp-content/plugins/` kopieren, Plugin aktivieren,
dann den Shortcode in eine Seite einfügen:

    [koeder_labor]
    [koeder_labor theme="dark" tab="quiz"]

- `theme`: `auto` (folgt dem System, Standard), `light`, `dark`
- `tab`: `lab` (Standard), `quiz`, `wissen`

Eigenschaften
-------------

- Labor: Gewässer, Struktur, Wasserklarheit, Jahreszeit, Temperatur, Wetter,
  Wind und Tageszeit einstellen. Empfohlen werden Köder, Farbe, Gewicht,
  Größe/Form und Führung, jeweils mit Begründung und animierter Führung.
- Prüfung: Zufällige Szenarien mit Auswertung und Rang.
- Grundlagen: Farbtest nach Tiefe und Köder-Lexikon.
- Assets werden nur auf Seiten mit Shortcode geladen (JS mit `defer`).
- Keine externen Requests, keine Schriften von Drittanbietern (DSGVO-freundlich),
  nur `localStorage` für den Prüfungsstand.

Hinweis: Die Empfehlungen sind Faustregeln. Schonzeiten, Mindestmaße und
Fischereischein richten sich nach Bundesland und Gewässer.
