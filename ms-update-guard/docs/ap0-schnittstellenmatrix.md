# Arbeitspaket 0 – Schnittstellenmatrix und Gate-Entscheidung

**Stand:** 26.09.2026 · **Ergebnis:** Gate 2 erfüllt · Gate 1 **nur mit Probe auf dem Child** erfüllt (Freigabe nötig, siehe unten) · keine Restore-Garantie ohne Staging-Restore-Probe

## 0. Geprüfte Versionen und Grenzen dieser Prüfung

Die tatsächlich bei dir installierten Versionen kenne ich nicht. Geprüft wurde der aktuelle öffentliche Quelltext; die Tabelle ist vor dem Pilot mit dem Ist-Stand abzugleichen:

```bash
# Dashboard
wp plugin list --fields=name,version --name=mainwp,mainwp-time-capsule-extension,mainwp-regression-testing-extension
# je Child (oder MainWP > Sites > Plugins)
wp plugin list --fields=name,version --name=mainwp-child,wp-time-capsule
```

| Komponente | Geprüfter Stand | Quelle | Prüftiefe |
| --- | --- | --- | --- |
| MainWP Dashboard | 6.2, Commit `2aa9884` (09.09.2026) | github.com/mainwp/mainwp | Quelltext + lokaler Lauf (WordPress trunk, MariaDB) |
| MainWP Child | 6.2, Commit `cc0f77c` (09.09.2026) | github.com/mainwp/mainwp-child | Quelltext + lokaler Lauf gegen das Dashboard |
| WP Time Capsule | 1.22.24, Commit `de410d8` (17.09.2025) | github.com/revmakx/wp-time-capsule (Entwickler-Repo; wordpress.org war aus der Umgebung nicht erreichbar) | Quelltext + lokaler Lauf **ohne** WPTC-Cloud-Konto |
| MainWP Time Capsule Extension | – | kommerziell, Quelltext nicht verfügbar | **nicht prüfbar** – wird vom Guard nicht benötigt |
| MainWP Regression Testing | – | kommerziell, Quelltext nicht verfügbar; Child-Seite `class-mainwp-child-html-regression.php` vorhanden | **nicht prüfbar** → Status „nicht angebunden“ |

Was **nicht** belegt werden konnte (fehlt für die Abnahme, siehe [`abnahme.md`](abnahme.md)): ein echtes WPTC-Backup in einen Remote-Speicher, das Verhalten bei abgebrochenem Upload in der Praxis und ein Restore aus einem vom Guard bestätigten Restore Point. Dafür ist ein WPTC-Konto auf Staging nötig.

## 1. Matrix

Zeilenformat: Schnittstelle · Aufrufkontext · Rückgabe · Fehlerverhalten · Beleg (Datei:Zeile im geprüften Stand) · Test.

### A – MainWP Dashboard 6.2

| # | Schnittstelle | Kontext | Rückgabe | Fehlerverhalten | Beleg | Test |
| --- | --- | --- | --- | --- | --- | --- |
| A1 | Ability `mainwp/sync-sites-v1` | in-process `wp_get_ability()->execute()`, Nutzer mit `manage_options` (Guard nutzt einen Service-Admin) | `synced[]`, `errors[]`; bis 200 Sites synchron | Site im `errors[]`, sonst `WP_Error` | `includes/abilities/class-mainwp-abilities-sites.php:161`, `:863` | E2E S1–S11 (echt) |
| A2 | Ability `mainwp/get-site-updates-v1` | wie A1, nach A1 | `updates[type, slug, current_version, new_version]` aus dem Sync-Stand | `WP_Error` | `…/class-mainwp-abilities-updates.php:211`, `:2301` | E2E S1, S10 |
| A3 | Abilities `mainwp/update-site-plugins-v1`, `…-themes-v1`, `…-core-v1` | wie A1; ruft Child `upgradeplugintheme` / `upgrade` | pro Slug `updated[]` / `errors[]` | Child-Fehler je Slug; Ausnahmen werden zu `errors[]`; Core: `WP_Error` | `…updates.php:240/269/298`, `:3417`, `:3488` | E2E S1, S4, S5 |
| A3a | **Einschränkung:** `updated[].new_version` ist die *erwartete* Version aus dem Inventar, nicht die installierte | – | – | Response kann Erfolg melden, obwohl nichts installiert wurde | `…updates.php:3545` | E2E S4 (Paket mit falscher Version → `FAIL`) |
| A4 | Abilities `get-site-plugins-v1`, `get-site-themes-v1`, `get-site-v1` (`wp_version`, `last_sync`) | wie A1, nach A1 | installierte Versionen laut letztem Sync | Sync fehlgeschlagen → Guard wertet `sync_failed`, nie „aktualisiert“ | `…sites.php:132/190`, `:1037` | E2E S1, S7 |
| A5 | Extension-API Filter `mainwp_fetchurlauthed` (signierter Aufruf Dashboard → Child) | Guard-Plugin-Datei + Key aus `mainwp_extension_enabled_check` | Child-Antwort als Array | `error`/`errorCode` | `class/class-mainwp-system-handler.php:91`, Key: `pages/page-mainwp-extensions-handler.php:554/594` | E2E (WPTC-Protokoll + Probe echt) |
| A6 | Native Auto-Updates (Cron) | Optionen `mainwp_pluginAutomaticDailyUpdate`, `…theme…`, `…trans…`, `mainwp_automaticDailyUpdate` | – | **kein Veto-Hook vor dem Update**; `mainwp_before_plugin_theme_translation_update` ist nur eine Action | `class/class-mainwp-cron-jobs-auto-updates.php:140–143`, `:698` | E2E S9: per `pre_option_*` erzwungen aus |
| A7 | Filter `mainwp_run_update_result` | nur in Bulk-Abilities `run-updates-v1`/`update-all-v1` | `WP_Error` bricht Site ab | – | `…updates.php:1949` | E2E S9: außerhalb eines Guard-Laufs abgewiesen |
| A8 | Actions `mainwp_after_plugin_theme_translation_update`, `mainwp_after_wp_update`, `mainwp_after_core_update` | UI-Updates (`page-mainwp-updates-handler.php:1204/1222`), Cron, Abilities | Child-Antwort | nur Erkennung, kein Veto | s. links; `class/class-mainwp-hooks.php:1492/1510` | E2E S9 (manuelles Update erkannt) |
| A9 | Action `mainwp_site_synced` | jeder Sync | vollständige Sync-Daten inkl. `plugins[slug,version]`, `themes`, `wpversion` | – | `class/class-mainwp-sync.php:772` | E2E S9 (Versionsänderung ohne Guard-Lauf erkannt) |
| A10 | MainWP-WP-CLI (`class-mainwp-wp-cli-handle.php:1315`), REST API v1/v2 (`…rest-api-v1.php:3106`, `…rest-updates-controller.php:1214`) | Updates über API/CLI | – | **lösen A8 nicht aus** | s. links | nur über A9 erkennbar |

### B – MainWP Child 6.2 + WP Time Capsule 1.22.24

| # | Schnittstelle | Kontext | Rückgabe | Fehlerverhalten | Beleg | Test |
| --- | --- | --- | --- | --- | --- | --- |
| B1 | Child-Callable `time_capsule`, `mwp_action=abilities_v2` (geschlossenes Protokoll „2“) | über A5 | `capabilities`: `site, policy, list_backups, operation_status, preview_restore, list_staging, replace_policy, start_backup, cancel_operation` | Unbekanntes → `unsupported_operation` | Child `class/class-mainwp-child-callable.php:125`; `class-mainwp-child-timecapsule.php:719` | echt gegen lokales Child |
| B2 | `start_backup` (scope `full`), `request_ref` = UUIDv4 | Mutation unter MySQL-`GET_LOCK`, Quittung vor Start dauerhaft gespeichert | `operation_ref`, `state` (`running`/`reconciliation_required`) | `outcome_unknown`, `lock_busy`, `state_conflict` (Backup läuft schon), `stale_generation`; Wiederholung mit gleichem `request_ref` startet **kein** zweites Backup (24 h) | `…timecapsule.php:896`, Quittungen `:444–531` | Guard nutzt `run_id` als `request_ref` |
| B3 | `operation_status` | Lesen | nach Ende **immer `uncertain`** | – | Kommentar im Code: „Time Capsule keeps no per-operation outcome … Nothing here can tell this operation's success from another's“ `…timecapsule.php:952–956` | – |
| B4 | `site` | Lesen | `plugin_state`, `account_state`, `active_operation_count`, `last_attempt_at` (= WPTC `last_backup_time`), `last_verified_at` **immer `null`** | `provider_schema_invalid` | `…timecapsule.php:1299–1313` | echt: `account_state=disconnected` → Guard `BLOCKED` (E2E S2) |
| B5 | `list_backups` | Lesen | Backup-IDs aus `wptc_processed_files`; `state` **fest `verified`**, `scope` **fest `full`** | – | `…timecapsule.php:1077–1105` (`'state' => 'verified'` in `:1097`) | Guard nutzt B5 **nicht** als Nachweis |
| B6 | `policy` | Lesen | `schedule_time`, `retention_days`, `backup_before_update` | ohne WPTC-Grundkonfiguration `provider_schema_invalid` | `…timecapsule.php:1336` | echt (lokal ohne Konto: `provider_schema_invalid`) |
| B7 | WPTC Abschlusslogik | Child-intern | `last_backup_time` wird **nur** im regulären Abschluss gesetzt (`complete_backup`), nicht beim Zwangsabbruch (`force_complete`) | Fehler einzelner Dateien landen in `mail_backup_errors`, Backup gilt trotzdem als abgeschlossen | WPTC `Classes/Config.php:496–515`, `:580`; `Classes/BackupController.php:392`; Reset der Fehler bei Backupstart `wp-time-capsule.php:769` | Unit-Tests `tests/unit.php` |
| B8 | WPTC-Tabellen je Backup-ID: `wptc_backups` (Meta-Zeile, `files_count`), `wptc_processed_files` (`backup.sql*`, `offset`/`uploadid` offener Uploads) | Child-DB | – | – | WPTC `wp-time-capsule.php:905–1025`, `Classes/Processed/Files.php:142–202` | über B9 |
| B9 | Filter `mainwp_child_extra_execution` | Child-Callable `extra_execution`, nur über A5 erreichbar | beliebige Zusatzdaten | – | Child `class-mainwp-child-callable.php:127`, `:867` | **Evidence-Probe** (`child-probe/`), E2E echt |
| B10 | WPTC eigene Auto-Updates / „Backup vor Update“ | Child | WPTC hängt an `auto_update_core/theme/plugin/translation` und eigenen Update-Abläufen | ungeklärt, ob MainWP-getriggerte Upgrades verzögert werden | WPTC `Pro/BackupBeforeUpdate/Hooks.php:37–75` | Guard verlangt „Backup vor Update“ = aus (Preflight); **auf Staging prüfen** |

### C – Nicht öffentlich

| # | Komponente | Befund | Folge im Guard |
| --- | --- | --- | --- |
| C1 | MainWP Time Capsule Extension | Kein Quelltext, keine dokumentierte Ereignis-API. Die Child-Seite (B1) ist öffentlich und genügt. | nicht benötigt |
| C2 | MainWP Regression Testing | Keine belegbare Ergebnis-Schnittstelle je Lauf. | Status `nicht angebunden`; Filter `msug_regression_status` für eine spätere, belegte Anbindung |

## 2. Antworten auf die fünf Prüffragen

1. **WPTC-Backup gezielt starten und Abschluss belegen?** Starten: **ja**, idempotent (B2). Abschluss: **nur teilweise** mit Bordmitteln – B3/B4 sagen selbst, dass sie Erfolg nicht zuordnen können; B5 behauptet `verified` ohne Prüfung. Datenbank-Anteil, offene Uploads und Backup-Fehler sind ohne Zusatz nicht abrufbar. **Mit** der lesenden Probe (B9 + B8) prüft der Guard pro Backup-ID: Meta-Zeile + Dateien, `backup.sql` vorhanden und vollständig hochgeladen, keine offenen Uploads, Erfolgsmarker, keine Backupfehler, Cloud-Ziel verbunden, `home_url` passt zur Site, Alter im Fenster. Kein Scraping.
2. **Alle Auto-Update-Pfade vor Ausführung kontrollieren?** **Ja, durch Ersetzen:** Nativer Cron (A6) wird per `pre_option_*` hart auf „Aus“ gezwungen; Bulk-Abilities (A7) werden außerhalb eines Guard-Laufs abgewiesen; der Guard-Scheduler (`wp msug tick` + `schedule`) ist der einzige automatische Weg und ruft A3 erst nach `BACKUP_READY`. Ein „after update“-Hook wird nur zur Erkennung genutzt.
3. **Belastbares Ergebnis pro Komponente?** **Nein** – A3 liefert Behauptungen (A3a). Der Guard rekonstruiert per Sync + Versionsvergleich (A1/A4) und bewertet Widersprüche (`UNKNOWN`/`FAIL`).
4. **Konkurrierende Updatewege?** Nicht vorab blockierbar: manuelle MainWP-Updates (UI), MainWP-WP-CLI/REST (A10), WordPress-Auto-Updates auf dem Child, WPTC-Auto-Updates (B10), andere Plugins. Sie werden **erkannt und markiert** (A8 sofort, A9 beim nächsten Sync) als `OUTSIDE_GUARD_UPDATE` mit `backup_checked: false` plus Alarm. Betrieblich abzuschalten: siehe [`betrieb.md`](betrieb.md#konkurrierende-updatewege).
5. **Regression Testing je Lauf zuordenbar?** **Nicht belegbar** (C2) → getrennte Dimension, Anzeige „nicht angebunden“, keine Aggregation.

## 3. Gate-Entscheidung

| Gate | Ergebnis | Begründung |
| --- | --- | --- |
| (2) Kontrolle vor Updatebeginn | **erfüllt** für alle Guard-gesteuerten Updates | Eigener Scheduler ersetzt die native Queue; Update nur aus `BACKUP_READY` (Zustandsautomat erzwingt das in Code und Unit-Test). Nicht steuerbare Wege werden sichtbar gemacht. |
| (1) Backup-Abschluss belegbar | **nur mit Evidence-Probe** | Ohne Probe endet jeder Lauf mit `BLOCKED evidence_insufficient` (bewusst, kein best-effort-Zeitabstand). |

### Entscheidung, die du treffen musst: Evidence-Probe auf den Children

Die Spezifikation sagt „Guard nur auf dem Parent“. Ein belastbarer Backup-Nachweis ist mit dem heutigen Stand von MainWP Child + WPTC aber nur mit **einer kleinen lesenden Datei pro Child** möglich:

- `child-probe/msug-wptc-evidence-probe.php` als **Must-Use-Plugin**, ~150 Zeilen, keine Schreibzugriffe, keine Einstellungen, keine UI.
- Nur über MainWPs signierte Verbindung erreichbar (`extra_execution`), antwortet nur auf `msug_probe=backup_evidence`.
- Verteilbar über MainWP (Code Snippets / File Uploader) oder Deployment.

**Alternativen und Auswirkungen:**

| Option | Aufwand | Sicherheit der Backup-Schranke |
| --- | --- | --- |
| **A: Probe ausrollen (empfohlen)** | gering (1 Datei, ~70 Sites) | Nachweis pro Backup-ID inkl. DB und Uploads; Restore-Fähigkeit weiterhin nur per Staging-Probe belegt |
| B: WPTC/MainWP um eine offizielle Evidenz-Operation bitten (z. B. `last_verified_at`, DB-Umfang in `abilities_v2`) | extern, unbestimmt | wie A, sobald verfügbar; Adapter ist dafür vorbereitet |
| C: Anderer Backup-Provider mit belegbarer API (z. B. Host-Snapshots) | mittel–hoch | abhängig vom Anbieter; Adapter-Schnittstelle `Backup_Provider` ist austauschbar |
| D: Ohne Probe | – | **Keine Guard-Updates** (alle Läufe `BLOCKED`) – der Guard bleibt dann nur Erkennung + Journal |

Ein „Backup gestartet, 30 Minuten warten, dann updaten“ wird bewusst **nicht** angeboten.
