# Abnahme – Nachweise und offener Staging-Teil

## Testläufe (26.09.2026)

| Suite | Befehl | Ergebnis |
| --- | --- | --- |
| Entscheidungslogik (PHP, ohne WordPress) | `php tests/unit.php` | 68/68 bestanden |
| Runner (Node, inkl. echtem Chromium) | `MSUG_CHROMIUM_PATH=… npm test` in `runner/` | 19/19 bestanden |
| End-to-End lokal | `tests/integration/run-local.sh` | 56/56 Prüfungen bestanden, aus leerem Verzeichnis |
| WordPress Coding Standards + PHPCompatibility 7.4+ | `phpcs --standard=phpcs.xml.dist` | 0 Befunde |

**Was im End-to-End-Test echt ist:** WordPress-Dashboard mit MainWP 6.2 und dem Guard, eine per MainWP verbundene Child-Site mit MainWP Child 6.2, WP Time Capsule 1.22.24 und der Evidence-Probe, echte Plugin-Updates über MainWP (lokale Pakete), echter Runner mit HTTP-Check und Chromium, signierte Callbacks über die REST-API, MariaDB.

**Was simuliert ist:** WPTC kann ohne Cloud-Konto kein Backup erstellen. Deshalb liefert in S1 und S3–S11 ein Test-Double (`tests/integration/fixtures/dashboard-backup-double.php`) die Nachweisdaten **im Format des echten Protokolls**; die Entscheidung trifft der echte Code (`Backup_Evidence`). In S2 läuft der echte WPTC-Weg (Konto getrennt → `BLOCKED`). Zeitsprünge (Deadline überschritten, Absturz während des Updates) werden per SQL herbeigeführt.

## Abschnitt 8 der Spezifikation

| Fall | Erwartet | Nachweis |
| --- | --- | --- |
| Backup erfolgreich, Remote bestätigt, Update/Test ok | `PASS`, Kette mit Restore-Point-ID | E2E **S1**: `PASS`, `BACKUP_READY` vor `UPDATE_STARTED`, Restore-Point-ID, Vorab- und Nachtest, Version per Resync, Lock frei, kein Alarm; S10 (geplanter Lauf) |
| WPTC-Job läuft noch / scheitert / Remote fehlt / Timeout | `BLOCKED`, kein Update, Alarm | E2E **S2** (echtes WPTC, Konto getrennt), **S3** `backup_failed`, `backup_timeout`; Unit: laufend, abgebrochen, Remote getrennt, Uploads offen, Fehler im Backup |
| Backup gehört zu anderer Site / zu alt / nur DB | `BLOCKED` mit Grund | E2E **S3** `backup_other_site`, `files_missing`, `evidence_insufficient`; Unit: `backup_too_old`, `backup_not_fresh`, `database_missing`, `backup_id_mismatch` |
| HTTP 500 nach Update / Child stirbt vor Callback | externer Check, `CRITICAL`, kein `PASS` | E2E **S5**: `FAIL`/`CRITICAL`, Runner sah 500, Browser übersprungen, Alarm mit Lauf, Restore Point, Link; Unit: Child tot + Sync fehlgeschlagen → `FAIL CRITICAL`; kein Runner-Ergebnis → `UNKNOWN CRITICAL` |
| Response „Erfolg“, Version unverändert | `UNKNOWN`/`FAIL` | E2E **S4**: `FAIL`, Grund `response_success_but_version_unchanged`, Site bleibt gesperrt bis Resolve |
| HTTP 200, Formular-/Warenkorbtest scheitert | `FAIL` mit Test und Artefakt | Runner-Tests: fehlendes Element → `SMOKE_FAIL` + Screenshot; leerer Warenkorb → `shop:cart` fail; Unit: `SMOKE_FAIL` → `FAIL ERROR` |
| Parent-/Runner-Neustart, doppelte Callbacks | Fortsetzung, keine Doppelupdates/-alarme | E2E **S7** (Absturz nach `UPDATE_STARTED` → kein zweiter Updateaufruf, `UPDATE_NO_RESULT`), **S8** (gefälschter Callback 401, verspäteter 409, Replay 200 ohne Wirkung); Runner-Test: Neustart setzt Test fort und stellt zu; Idempotenz `run_id`/`test_id`; Alarm-Dedupe per Primärschlüssel |
| Update auf nicht kontrolliertem Pfad | sichtbar oder unterbunden | E2E **S9**: Bulk-Ability abgewiesen, manuelles MainWP-Update als `OUTSIDE_GUARD_UPDATE` (`backup_checked:false`) + Alarm, native Auto-Updates erzwungen aus |
| Regression Testing nicht angebunden | „nicht angebunden“, kein `PASS` | E2E **S1**: `regression.state = not_connected`, eigene Spalte |
| Parent-Scheduler steht still | externer Heartbeat meldet | E2E **S11**: jeder Tick pingt; Ausbleiben meldet der externe Dienst (Better Stack Heartbeat, Einrichtung in `betrieb.md`) |

## F01–F10

| ID | Umsetzung | Nachweis |
| --- | --- | --- |
| F01 | `active_lock` mit UNIQUE-Index; Komponenten mit Soll/Ist | E2E S1/S4 (zweiter Lauf abgewiesen) |
| F02 | `start_backup` mit `run_id` als `request_ref`; Backup-ID, Zeit, Umfang, Remote gespeichert; optionale Wiederverwendung nur mit gleicher Nachweisprüfung (`reuse_backup_min`, Standard 0) | E2E S1, Unit |
| F03 | `Backup_Evidence`: Dateien + DB + Uploads + Erfolgsmarker + Fehlerfreiheit + Remote + Site + Zeitfenster | Unit (18 Negativfälle), E2E S3 |
| F04 | Zustandsautomat erlaubt `UPDATE_RUNNING` nur aus `BACKUP_READY`; `UPDATE_STARTED` wird vor dem MainWP-Aufruf persistiert | Unit (Graphprüfung), E2E S1 |
| F05 | Test nach Erfolg, Fehler **und** Timeout; Playwright nur bei HTTP-Erfolg | E2E S5, S7 |
| F06 | Resync + Versionsvergleich, Widerspruch → `UNKNOWN`/`FAIL` | E2E S4, Unit |
| F07 | Append-only Journal, Alarme mit Site/Komponente/Grund/Backup/Test/Link | E2E S2, S4, S5 |
| F08 | Deadlines, Backoff, Leases, Replay-Schutz, Dedupe, manuelles Resolve | E2E S3, S7, S8 |
| F09 | eigene Dimension, `not_connected` | E2E S1 |
| F10 | Capability + Nonce, Secrets in `wp-config`, HMAC mit Ablauf und Ereignis-ID, getrennte Secrets, Allowlist + https, Runner ohne freie URL | E2E S8, Runner-Tests (fremder Host, falscher Schlüssel, Replay, fremde Site/Profil) |

## Offen für die Staging-Abnahme (braucht WPTC-Konto)

Diese Punkte konnte ich ohne WPTC-Cloud-Konto nicht belegen. Sie gehören in das Staging-Protokoll, bevor der Guard produktiv Updates ausführt:

1. **Echter Backup-Durchlauf** auf einer Staging-Kopie: Guard-Lauf bis `BACKUP_READY`; im Journal `BACKUP_STATUS` → `backup_ready`; Probe-Werte (`files_count`, `db_dump`, `incomplete_uploads = 0`, `error_count = 0`) mit dem WPTC-Aktivitätslog vergleichen.
2. **Negativfälle mit echtem WPTC:** Remote-Verbindung während des Backups trennen (erwartet `upload_incomplete`/`backup_errors`), Backup abbrechen (`backup_not_completed`), zweites Backup parallel (`wptc_backup_already_running`).
3. **Inkrementelle Backups:** prüfen, dass `backup.sql` bei jedem Lauf hochgeladen wird. Fehlt die DB bei unveränderter Datenbank, blockiert der Guard (`database_missing`) – sicher, aber ggf. zu streng.
4. **Restore-Probe:** vom Guard bestätigten Restore Point auf Staging tatsächlich zurückspielen, danach HTTP + Smoke.
5. **WPTC „Backup vor Update“ (B10):** einmal eingeschaltet testen, ob MainWP-Updates verzögert werden; der Guard verlangt es standardmäßig „aus“.
6. **Pilot:** 3 Sites (Corporate, WooCommerce mit Testprodukt, Spezialintegration), Parallelität 1, eine Woche; dann Kapazität (Backup-Dauer, Runner-Laufzeit) messen und Timeouts pro Site setzen.
