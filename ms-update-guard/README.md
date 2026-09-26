# MS Update Guard

Backup-gesteuerte, protokollierte Updates für MainWP-Child-Sites. Läuft **nur auf dem MainWP-Dashboard**; ein externer Runner prüft jede Site nach jedem Updateversuch von außen (HTTP + Playwright).

**Kernregel:** Ein vom Guard gesteuertes Update startet erst, wenn für genau diese Site ein zuordenbarer, abgeschlossener WP-Time-Capsule-Restore-Point mit Dateien **und** Datenbank im Remote-Speicher belegt und gespeichert ist (`BACKUP_READY`). Fehlt ein Nachweis, gibt es kein Update (`BLOCKED`) – kein Zeitabstand als Ersatz.

```
QUEUED → PREFLIGHT → BACKUP_PENDING → BACKUP_READY → UPDATE_RUNNING → VERIFYING → TESTING → PASS | FAIL | UNKNOWN
            └──────────────┴──────────────┴──→ BLOCKED (vor jedem Update)
```

## Wichtig vor dem Einsatz

- **Arbeitspaket 0:** [`docs/ap0-schnittstellenmatrix.md`](docs/ap0-schnittstellenmatrix.md). Ergebnis: Kontrolle vor dem Update ist gegeben; der Backup-Nachweis braucht eine **kleine lesende Probe pro Child** (`child-probe/`). Ohne Probe blockiert der Guard jedes Update. Diese Abweichung von „nur Parent“ musst du freigeben.
- **Staging-Abnahme mit echtem WPTC-Konto** steht noch aus: [`docs/abnahme.md`](docs/abnahme.md#offen-für-die-staging-abnahme-braucht-wptc-konto).

## Inhalt

| Pfad | Zweck |
| --- | --- |
| `ms-update-guard.php`, `includes/` | Dashboard-Plugin: Zustandsautomat, Journal, Adapter (MainWP-Abilities, WPTC über MainWP Child), Alarmierung, REST-Callback, Admin-Seite, WP-CLI |
| `child-probe/` | Must-Use-Plugin für Children: lesender Backup-Nachweis |
| `runner/` | Externer Test-Runner (Node + Playwright): HTTP-Check, Profile `corporate-basic`, `woocommerce`, `http-only` |
| `docs/` | Spezifikation, Schnittstellenmatrix, Betrieb/Runbook, Abnahme |
| `tests/` | Unit-Tests (`php tests/unit.php`), lokaler End-to-End-Lauf (`tests/integration/run-local.sh`) |

## Schnellstart

```bash
wp plugin activate ms-update-guard
wp msug doctor                     # Voraussetzungen prüfen
# System-Cron: * * * * * cd /pfad/zu/wp && wp msug tick --quiet
wp msug enqueue 43 --plugins=akismet/akismet.php
wp msug status --open
```

Einrichtung, Secrets, Runner, konkurrierende Updatewege und Runbook: [`docs/betrieb.md`](docs/betrieb.md).

## Entwicklung

```bash
php tests/unit.php
(cd runner && npm ci && MSUG_CHROMIUM_PATH=/pfad/zu/chrome npm test)
WORKDIR=/tmp/msug-e2e MSUG_CHROMIUM_PATH=/pfad/zu/chrome tests/integration/run-local.sh
phpcs --standard=phpcs.xml.dist    # WordPress Coding Standards + PHPCompatibility
```

Adapter sind über den Filter `msug_adapters` austauschbar (z. B. anderer Backup-Provider). Weitere Hooks: `msug_capability`, `msug_alert`, `msug_regression_status`.
