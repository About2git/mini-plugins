# Betrieb, Einrichtung und Runbook

## Unterstützte Versionen

| Komponente | Mindestens | Geprüft |
| --- | --- | --- |
| WordPress (Dashboard) | 6.9 (Abilities API) | 7.2-alpha (trunk) |
| PHP | 7.4 | 8.4 |
| MainWP Dashboard | 6.2 (Abilities `mainwp/*-v1`) | 6.2 |
| MainWP Child | 6.2 (`time_capsule` → `abilities_v2`) | 6.2 |
| WP Time Capsule | 1.22.x | 1.22.24 (ohne Cloud-Konto) |
| Datenbank | MySQL 5.7 / MariaDB 10.4 | MariaDB 10.11 |
| Runner | Node.js 20, `playwright-core` 1.56.1 + Chromium | Node 22 |

`wp msug doctor` prüft die Voraussetzungen des Dashboards.

## 1. Installation auf dem Dashboard

1. Ordner `ms-update-guard/` nach `wp-content/plugins/` kopieren (ohne `runner/`, `tests/`), aktivieren. Die Tabellen `wp_msug_runs`, `wp_msug_events`, `wp_msug_receipts`, `wp_msug_alerts` werden angelegt.
2. **Service-Benutzer:** einen eigenen Administrator anlegen (z. B. `msug-service`, starkes Passwort, kein Login nötig) und seine ID unter *Werkzeuge › MS Update Guard › Settings* eintragen. Cron und WP-CLI handeln als dieser Benutzer gegenüber MainWP.
3. **Secrets** in `wp-config.php` (bevorzugt; das Formular ist nur Fallback):
   ```php
   define( 'MSUG_TRIGGER_SECRET', '…mind. 32 Zeichen…' );   // Parent -> Runner, Aufträge
   define( 'MSUG_STATUS_SECRET', '…' );                     // Parent -> Runner, Statusabfrage (und Antwortsignatur)
   define( 'MSUG_CALLBACK_SECRET', '…' );                   // Runner -> Parent, Ergebnisse
   // Während einer Rotation zusätzlich:
   // define( 'MSUG_CALLBACK_SECRET_PREVIOUS', '…alter Schlüssel…' );
   ```
   Erzeugen: `openssl rand -hex 32`. Drei **verschiedene** Werte.
4. **System-Cron statt WP-Cron** (`define( 'DISABLE_WP_CRON', true );`):
   ```cron
   * * * * * cd /pfad/zu/wordpress && wp msug tick --quiet >> /var/log/msug-tick.log 2>&1
   ```
   `tick` plant fällige Läufe ein (einmal je Site und Tag im Wartungsfenster), treibt alle Läufe einen Schritt weiter, verschickt Erinnerungen und pingt den Heartbeat. Ein zweiter, überlappender Aufruf beendet sich sofort.
5. **Allowlist und Ziele:** *Allowed outbound hosts* = Hostname des Runners und der Alarm-/Heartbeat-Dienste. Der Guard ruft ausschließlich `https`-URLs auf diesen Hosts auf.
6. **Alarmierung:** E-Mail-Adressen und/oder eine Webhook-URL (z. B. Better Stack Incoming Webhook, Slack-Relay). Payload: `run_id, site_id, state, severity, reasons, url, subject, message`.
7. **Heartbeat:** Better Stack → *Heartbeats* → neuer Heartbeat, Intervall 5 min, Karenz 5 min. URL im Guard eintragen. Bleibt der Ping aus (Parent, Cron oder DB tot), alarmiert Better Stack – auch wenn der Guard selbst nichts mehr senden kann.

## 2. Evidence-Probe auf den Children (Entscheidung siehe AP0)

`child-probe/msug-wptc-evidence-probe.php` nach `wp-content/mu-plugins/` jeder Guard-Site. Verteilung z. B. mit MainWP *File Uploader* in `wp-content/mu-plugins/`. Prüfen: `wp msug doctor <site_id>` → `WPTC preflight site #… : ready`.

## 3. WPTC und MainWP einrichten

Pro Guard-Site auf dem Child:

- WPTC mit Konto verbunden, Remote-Speicher verbunden, mindestens ein vollständiges Backup vorhanden.
- WPTC *Backup/Auto Updates*: „Backup before updates“ **aus** (der Guard sichert selbst; sonst `wptc_backup_before_update_enabled`), WPTC-Auto-Updates **aus**.
- WPTC-Zeitplan so legen, dass er nicht in das Guard-Wartungsfenster fällt (laufendes Backup → Preflight wartet, dann Timeout).

Auf dem Dashboard pro Site (*Werkzeuge › MS Update Guard › Sites*): *Enabled*, Runner-Profil-ID (muss in der Runner-Konfiguration für diese Site-ID erlaubt sein), *Scheduled types* (plugin/theme/core), optional Ausschlüsse, kritische Komponenten, Wartungsfenster (`HH:MM-HH:MM`, Zeitzone der WordPress-Einstellungen), Timeouts, eigene Alarmadressen.

Globale Defaults (nur Vorschläge): Backup-Timeout 90 min, Update-Timeout 15 min, Test-Timeout 20 min, Settle 45 s, maximales Backup-Alter 120 min, Wiederverwendung eines frischen Restore Points 0 min (= immer neu sichern), Parallelität 2 (Spezifikation: 1–3).

## Konkurrierende Updatewege

| Weg | Vom Guard | Was du tun musst |
| --- | --- | --- |
| MainWP native Auto-Updates (Cron) | **hart aus** (per `pre_option_*`, solange „Force MainWP native automatic updates off“ aktiv ist) | nichts; Einstellung im Guard aktiv lassen |
| MainWP Bulk-Abilities (REST/MCP `run-updates`, `update-all`) | **abgewiesen** außerhalb eines Guard-Laufs | nichts |
| MainWP UI „Update“ | erkannt sofort → `OUTSIDE_GUARD_UPDATE` + Alarm | Team informieren: Updates über den Guard einplanen |
| MainWP WP-CLI / REST-API v1/v2 | erkannt beim nächsten Sync | API-Keys ohne Update-Rechte vergeben |
| WordPress-Auto-Updates auf dem Child | erkannt beim nächsten Sync | pro Site deaktivieren (`AUTOMATIC_UPDATER_DISABLED` oder `auto_update_*`-Filter; Core-Minor bewusst entscheiden) |
| WPTC-Auto-Updates | erkannt beim nächsten Sync | in WPTC abschalten |
| andere Plugins (z. B. Premium-Updater) | erkannt beim nächsten Sync | abschalten oder als Ausnahme dokumentieren |

Alles, was erkannt wird, erscheint im Tab *Outside the Guard* mit `backup_checked: false` – nie als „Backup geprüft“.

## 4. Runner einrichten

Separater kleiner Server (nicht auf dem Dashboard-Host, damit er einen Ausfall des Parents überlebt).

```bash
sudo useradd -r -m -d /opt/ms-update-guard-runner msug-runner
sudo -u msug-runner git clone … /opt/ms-update-guard-runner   # nur runner/
cd /opt/ms-update-guard-runner && npm ci --omit=dev && npx playwright-core install chromium
cp config/runner.example.json config/runner.local.json          # Sites/Profile pflegen
sudo cp deploy/runner.env.example /etc/ms-update-guard-runner.env && sudo chmod 600 /etc/ms-update-guard-runner.env
sudo cp deploy/ms-update-guard-runner.service /etc/systemd/system/ && sudo systemctl enable --now ms-update-guard-runner
```

TLS terminiert davor Nginx (`deploy/nginx-runner.conf`), `/v1/` nur für die IP des Dashboards. Der Runner:

- nimmt nur signierte, nicht abgelaufene Aufträge mit neuer Ereignis-ID an; Wiederholung derselben Bytes = `409`,
- kennt Ziel-URLs ausschließlich aus seiner Konfiguration (`site_id` → `base_url`, `allowed_hosts`, erlaubte Profile); Zusatzfelder im Auftrag werden ignoriert,
- blockiert im Browser Navigation und Nicht-GET-Requests zu fremden Hosts,
- speichert Screenshots/Console-Auszüge unter zufälliger ID in `artifacts/` (nur die ID geht an den Parent),
- setzt unterbrochene Tests nach Neustart fort und stellt Ergebnisse erneut zu (neue Ereignis-ID je Versuch).

**Profile:** `http-only` (nur HTTP-Check), `corporate-basic` (Seiten, Navigation/CTA/Formular vorhanden, kritische JS-Fehler per Regex), `woocommerce` (Produkt → Warenkorb → Kasse, keine Zahlung; echte Testbestellung nur mit `sandbox_order.enabled` **und** `gateway_confirmed_sandbox`). Formularversand nur mit `form.submit` **und** `safe_target_confirmed`. Zugangsdaten für geschützte Seiten nur über Umgebungsvariablen (`login.user_env`/`pass_env`).

**Schlüsselrotation:** neuen Schlüssel beim Runner zusätzlich eintragen (`MSUG_TRIGGER_KEYS=k2:NEU,k1:ALT`), im Guard `key_id=k2` und neues Secret setzen, alten Schlüssel nach einem Tag entfernen. Callback: `MSUG_CALLBACK_SECRET_PREVIOUS` im Guard während der Umstellung.

## 5. Tagesbetrieb

- Übersicht: *Werkzeuge › MS Update Guard › Runs* (🔒 = Site gesperrt), Detailansicht mit Nachweisen und Journal.
- CLI: `wp msug status [--open]`, `wp msug status <run_id>`, `wp msug enqueue <site> --plugins=a/a.php`, `wp msug resolve <run_id> --note="…"`, `wp msug doctor [<site>]`.
- Aufbewahrung: Journal 400 Tage (einstellbar), Replay-Quittungen 30 Tage.
- Deinstallation behält alle Daten, außer `MSUG_DELETE_DATA_ON_UNINSTALL` ist `true`.

## 6. Runbook

**Grundsatz:** Die Site bleibt nach `FAIL`/`UNKNOWN` für Guard-Updates gesperrt, bis jemand den Lauf mit Notiz auflöst. `BLOCKED` und `PASS` geben die Site sofort frei (bei `BLOCKED` wurde nichts verändert).

### BLOCKED (WARNING) – kein Update ausgeführt

| Grund | Bedeutung | Vorgehen |
| --- | --- | --- |
| `wptc_account_disconnected`, `wptc_plugin_missing`, `wptc_remote_storage_not_connected` | WPTC nicht einsatzbereit | WPTC auf dem Child verbinden, Lauf neu einplanen |
| `evidence_probe_missing`, `evidence_insufficient` | Probe fehlt/antwortet nicht | Probe ausrollen (Abschnitt 2) |
| `wptc_backup_before_update_enabled` | WPTC-eigenes „Backup vor Update“ an | in WPTC abschalten |
| `backup_timeout`, `wptc_backup_already_running` | Backup zu lang / anderes Backup lief | WPTC-Aktivitätslog prüfen, Zeitplan entzerren, Timeout pro Site erhöhen |
| `backup_failed`, `backup_cancelled`, `backup_not_completed`, `backup_errors`, `upload_incomplete`, `database_missing`, `files_missing`, `completion_marker_missing`, `remote_unconfirmed` | Restore Point unbrauchbar | WPTC-Log der Site prüfen, manuelles Backup testen |
| `backup_other_site`, `backup_id_mismatch` | Nachweis passt nicht zur Site (z. B. geklonte Staging-Kopie mit gleicher WPTC-Verbindung) | WPTC-Verbindung/Site-URL prüfen |
| `backup_too_old`, `backup_not_fresh`, `backup_expired_before_update` | außerhalb des Zeitfensters | Fenster/`backup_max_age_min` prüfen |
| `preexisting_defect` | Site war schon **vor** dem Update defekt | Ursache beheben oder bewusst mit „accept pre-existing defect“ neu einplanen (wird journalisiert) |
| `site_unreachable`, `mainwp_unavailable`, `backup_provider_unreachable` | Verbindung/Sync gestört | MainWP-Sync der Site prüfen |
| `runner_not_configured`, `test_profile_missing`, `pretest_no_result` | Test-Infrastruktur fehlt | Runner/Profil prüfen |
| `update_not_available`, `nothing_to_update` (INFO) | nichts zu tun | – |

### FAIL (ERROR/CRITICAL) – Update ausgeführt, Fehler nachgewiesen

1. Alarm öffnen: Site, Komponenten, **Restore-Point-ID**, Testergebnis, Link.
2. Site im Browser prüfen; Artefakt (Screenshot/Console) auf dem Runner unter `artifacts/<id>.png|.console.txt`.
3. Entscheiden:
   - **Plugin/Theme deaktivieren/zurücksetzen** (schnell): per WP-CLI/SSH `wp plugin deactivate <slug>` oder Ordner umbenennen; vorige Version über MainWP-Rollback bzw. Paket.
   - **Restore über WPTC** zum genannten Restore Point (WPTC-Oberfläche des Childs oder MainWP Time Capsule). WPTC-Restore kann scheitern → danach immer HTTP + Smoke erneut prüfen.
   - **Bootet die Site nicht:** Host-Panel/SSH: `wp plugin deactivate <slug> --skip-plugins --skip-themes`, ggf. Host-Snapshot; danach WPTC-Restore.
4. `wp msug resolve <run_id> --note="was geprüft/wiederhergestellt"` (oder Button in der Detailansicht). Erst dann plant der Guard die Site wieder ein.

### UNKNOWN – Nachweise unvollständig oder widersprüchlich

Typische Gründe (`reason`): `no_runner_result`/`http_unknown` (Runner nicht erreichbar → **CRITICAL**, Site sofort selbst prüfen), `version_resync_failed` (Sync nach Update scheiterte), `response_error_but_version_changed:*`, `update_no_result` (Updateaufruf ohne Antwort, z. B. Worker-Abbruch – es gab **keinen** automatischen zweiten Versuch). Vorgehen: Site prüfen, MainWP-Sync manuell, installierte Version vergleichen, dann wie FAIL auflösen.

### Parent- oder Runner-Ausfall

- Heartbeat-Alarm → Dashboard/Cron/DB prüfen. Nach Neustart setzt `wp msug tick` alle Läufe am gespeicherten Zustand fort (Leases laufen nach Timeout ab). Ein Lauf, der in `UPDATE_RUNNING` hängen blieb, geht nach dem Update-Timeout ohne Wiederholung in die Verifikation.
- Runner-Ausfall → betroffene Läufe enden nach Test-Timeout mit `UNKNOWN`/CRITICAL. Nach Neustart holt der Runner offene Tests nach; der Parent fragt nach Fristablauf einmal den Status ab.

## 7. Nicht im MVP / bewusst nicht umgesetzt

- Automatisches Rollback.
- Notfallprozess „Update ohne Backup-Schranke“: nicht implementiert. Ein Notfall-Update erfolgt über MainWP direkt und erscheint als `OUTSIDE_GUARD_UPDATE` (Verantwortlicher/Begründung im Resolve-Kommentar bzw. Ticket).
- Pixel-Diff, Änderungen am MS Integrity Guard.
