# Geburtstagserinnerung (birthdayreminder)

Nextcloud-App für Vereine: verwaltet die Vereinsmitglieder in einem eigenen Mitgliederregister und verschickt

- **Erinnerungs-Mails an Verantwortliche** zu frei konfigurierbaren Vorlaufzeiten (z.B. 30/14/2/1 Tage vorher + am Tag selbst)
- **eine Glückwunsch-Mail direkt ans Mitglied**, sofern eine E-Mail-Adresse hinterlegt ist
- optional einen **Geschenkvorschlag** bei runden Geburtstagen (frei definierbare Jubiläumsalter)
- eine Übersicht der nächsten Geburtstage als **Dashboard-Widget** sowie auf der Mitgliederseite selbst (inkl. Kreisdiagramm „Geburtstage pro Monat" und Balkendiagramm „Altersstruktur")

Läuft komplett auf dem Nextcloud-Server selbst (eigener Background-Job, nutzt den bereits konfigurierten Mailer) — kein externes Skript, kein separater Cron-Eintrag, kein Node/Build-Schritt auf dem Server nötig.

Die Mitgliederdaten (Vorname, Nachname, Geburtsdatum, E-Mail, Deaktiviert-Schalter, Bemerkung) liegen in einem **eigenen Mitgliederregister** der App — nicht in den Nextcloud-Kontakten. Das Register hat eine eigene Seite in der Nextcloud-Menüleiste, inklusive **CSV-Import** mit Spalten-Zuordnung, der neue Mitglieder anlegt, geänderte aktualisiert und beim Import fehlende automatisch deaktiviert.

## Status

| Meilenstein | Beschreibung | Status |
|---|---|---|
| M1 | Terminberechnung | ✅ fertig, verifiziert |
| M2 | Persistenz + Erinnerungs-Mails | ✅ fertig, verifiziert |
| M3 | Glückwunsch-Mail ans Mitglied | ✅ fertig, verifiziert |
| M4 | Admin-Einstellungsseite (Gruppen-Delegation) | ✅ fertig, verifiziert |
| M4b | Persönliche Einstellungsseite | ✅ fertig, verifiziert |
| M5 | Dashboard-Widget | ✅ fertig, verifiziert |
| M6 | Deploy auf die echte Vereins-Instanz | ⏳ offen |
| M7 | Eigenes Mitgliederregister + CSV-Import (löst das Nextcloud-Adressbuch als Datenquelle ab) | ✅ fertig, verifiziert |
| M8 | Konfigurierbare tägliche Prüfzeit, manueller Sofort-Versand, einsehbares/löschbares Versand-Log | ✅ fertig, verifiziert |
| M9 | Mitgliederseite als Sidebar-Navigation (Übersicht mit Diagrammen/Mitgliederliste/CSV-Import/Logs); feste Zugriffsgruppen statt frei wählbarer Gruppe | ✅ fertig, verifiziert |

„Verifiziert" heißt: Ende-zu-Ende live gegen eine echte Nextcloud-34-Testinstanz getestet — siehe [docs/plan.md](docs/plan.md) für die Details und den Architektur-Wechsel weg vom Adressbuch.

## Voraussetzungen

- Nextcloud ≥ 28 (getestet gegen 34.0.1)
- SSH-Zugriff (für `occ`-Befehle bei Installation/Konfiguration)

## Installation

```bash
# App-Verzeichnis in Nextclouds apps/ (oder custom_apps/) klonen
git clone <repo-url> apps/birthdayreminder

# aktivieren (führt automatisch die Datenbank-Migration aus)
php occ app:enable birthdayreminder
```

Die App registriert sich automatisch im vorhandenen Nextcloud-Cron (`cron.php`) — es ist **kein zusätzlicher Cron-Job** nötig.

## Einrichtung

1. **Mitgliederregister** (eigenes Icon oben in der Nextcloud-Menüleiste, sichtbar für berechtigte Nutzer — siehe [Zugriffsrechte](#zugriffsrechte)) öffnen: dort **Übersicht** (anstehende Geburtstage + Diagramme), **Mitgliederliste** (Erfassen/Bearbeiten), **CSV-Import** und **Logs** über die linke Seitenleiste
2. **Einstellungen → Verwaltung → Geburtstagserinnerung** öffnen (als Nextcloud-Admin oder Mitglied der Gruppe „Geburtstagserinnerung Admin"): Empfänger anlegen (Nextcloud-Nutzer, Gruppe oder feste E-Mail-Adresse) mit eigenen Vorlaufzeiten
3. Optional: runde Geburtstage mit Geschenkvorschlag hinterlegen
4. Optional: Text der Glückwunsch-Mail anpassen (Platzhalter `{name}`, `{vorname}`, `{alter}`, `{datum}`, `{wochentag}`)
5. Im Bereich „Zeitplan & manueller Versand": Uhrzeit für die tägliche Prüfung einstellen (Standard 08:00). Dort auch zwei Buttons, um Erinnerungen bzw. Glückwünsche sofort manuell auszulösen (respektiert dieselbe Versand-Historie wie der automatische Lauf, also keine doppelten Mails), sowie ein Button zum vollständigen Löschen des Versand-Logs (Warnung: hebt die Duplikat-Sperre für den Tag auf)

Das **Versand-Log** (Mitgliederseite, Bereich „Logs") zeigt die letzten 200 tatsächlich verschickten Mails mit Mitgliedsname, Art, Vorlaufzeit, Bezugsjahr und Zeitpunkt — nützlich, um nachzuvollziehen, warum an einem Tag (nicht) verschickt wurde.

Jeder Nextcloud-Nutzer kann außerdem unter **Einstellungen → Geburtstagserinnerung** selbst festlegen, zu welchen Vorlaufzeiten (bzw. nur bei runden Geburtstagen) er erinnert werden möchte.

### Zugriffsrechte

Zwei feste Nextcloud-Gruppen steuern den Zugriff — beim ersten `app:enable` automatisch angelegt (leer, Mitglieder selbst über Einstellungen → Benutzer hinzufügen):

| Gruppe | Mitgliederregister (Übersicht/Liste/CSV-Import/Logs) | Admin-Einstellungen (Empfänger/Meilensteine/Mail-Vorlage/Zeitplan) |
|---|---|---|
| `Geburtstagserinnerung Verantwortliche` | ✅ | ❌ |
| `Geburtstagserinnerung Admin` | ✅ | ✅ |
| echte Nextcloud-Systemadmins | ✅ | ✅ |
| alle anderen Nutzer | ❌ (auch kein Menüeintrag sichtbar) | ❌ |

Die Gruppen werden zwar automatisch angelegt, die eigentliche Berechtigung muss aber einmalig per SSH zugewiesen werden:

```bash
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\MemberAreaAccess" "Geburtstagserinnerung Verantwortliche"
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\MemberAreaAccess" "Geburtstagserinnerung Admin"
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\AdminSettings" "Geburtstagserinnerung Admin"
```

### CSV-Import

Auf der Mitgliederseite: CSV-Datei auswählen, Spalten den Feldern **Vorname**, **Nachname**, **Geburtsdatum** (Pflicht) und **E-Mail** (optional) zuordnen, dann importieren. Eine Beispieldatei liegt unter [docs/beispiel-mitglieder-import.csv](docs/beispiel-mitglieder-import.csv) (Semikolon-getrennt, Geburtsdatum als `TT.MM.JJJJ`; `TT.MM.` ohne Punkt am Ende geht auch, wenn kein Jahr bekannt ist).

Abgleich-Logik pro Import (Namensvergleich Vorname+Nachname, ohne Berücksichtigung von Groß-/Kleinschreibung):

- **neuer Name** → Mitglied wird angelegt
- **bekannter Name, Daten geändert** → Mitglied wird aktualisiert
- **bekannter Name, keine Änderung** → nichts passiert
- **bekannter Name fehlt in der CSV** → Mitglied wird deaktiviert, Bemerkung „Deaktiviert da bei Import nicht mehr vorhanden" wird ergänzt

Ein bereits deaktiviertes Mitglied wird **nicht automatisch wieder aktiviert**, nur weil es wieder in der CSV auftaucht — das bleibt bewusst eine manuelle Entscheidung auf der Mitgliederseite.

### Mail-Format

Alle Mails sind bewusst einfacher reiner Text — kein Nextcloud-Logo, keine Fußzeile, kein HTML-Vorlagen-Gerüst (`OCP\Mail\IEMailTemplate` erzeugt sonst einen großen Banner samt Restlayout, das auch ohne Header/Footer noch sichtbare Leerbereiche hinterlässt). Absender-Anzeigename ist „Geburtstagserinnerung" statt des Instanz-Theming-Namens; die eigentliche Absenderadresse kommt weiterhin aus der Nextcloud-Mailkonfiguration.

Die Erinnerungs-Mail an Verantwortliche nennt in Betreff und Text automatisch Geburtsdatum, Wochentag und (falls bekannt) das Alter; ist kein Geburtsjahr hinterlegt, steht explizit „Alter unbekannt" statt es wegzulassen.

## Entwicklung

```bash
composer install     # PHPUnit für die lokalen Tests
vendor/bin/phpunit    # reine Logik (Terminberechnung, CSV-/Datums-Parsing, Import-Abgleich) - läuft ohne Nextcloud-Runtime
```

Es gibt keinen Frontend-Build-Schritt: `js/` und `css/` sind handgeschriebenes Vanilla JS/CSS, direkt einsatzbereit (bewusste Abweichung vom ursprünglichen Plan, der Vue/Webpack vorsah — siehe [docs/plan.md](docs/plan.md)).

**Wichtig bei Änderungen an `js/`/`css/`:** Nextcloud hängt an jede Datei einen Cache-Buster (`?v=...`) an, der aus der `<version>` in `appinfo/info.xml` berechnet wird — nicht aus dem Dateiinhalt. Nach jeder JS/CSS-Änderung muss die Version dort erhöht werden, sonst liefern Browser die alte, gecachte Datei aus.

## Architektur (Kurzfassung)

```
appinfo/          info.xml, routes.php
lib/
  AppInfo/        Application.php (DI, Dashboard-Widget- + Event-Listener-Registrierung)
  Listener/       LoadNavigationEntryListener (zeigt den Menüeintrag nur berechtigten Nutzern)
  BackgroundJob/  DailyReminderJob.php (stündliche Prüfung, Versand einmal täglich zur eingestellten Uhrzeit)
  Model/          Member.php (Wertobjekt für die Terminlogik)
  Db/             Member (Mitgliederregister) + Recipient/Offset/Milestone/ReminderLog + Mapper
  Service/        ReminderCalculator (reine Terminlogik inkl. Alters-Bucketing), ReminderService (Orchestrierung),
                  MailService, RecipientResolver, MailTemplateRenderer, ConfigService,
                  CsvParser/MemberSyncPlanner (reine CSV-Import-Logik), CsvImportService (Orchestrierung),
                  ScheduleGate (reine Logik: "ist die eingestellte Tageszeit erreicht?"),
                  GermanDate (reine Logik: deutsche Wochentagsnamen)
  Controller/      PageController (Mitgliederseite), MembersApiController, AdminApiController, PersonalApiController
  Settings/        AdminSection/AdminSettings (IDelegatedSettings, nur "Geburtstagserinnerung Admin"),
                   MemberAreaSection/MemberAreaAccess (permission-only, beide Gruppen), PersonalSection/PersonalSettings
  Dashboard/       BirthdayWidget.php (IAPIWidgetV2)
  Migration/       Datenbank-Schema (5 Tabellen) + automatisches Anlegen der beiden Zugriffsgruppen
  Command/         occ-Befehle für Debug/Verwaltung
js/, css/, templates/   Vanilla-JS-Seiten (Mitgliederregister, Einstellungen) - kein Build-Schritt
tests/Unit/             PHPUnit-Tests für die reine Logik
docs/plan.md            Architektur-/Umsetzungsplan inkl. Architektur-Wechsel weg vom Adressbuch
docs/beispiel-mitglieder-import.csv   Beispieldatei für den CSV-Import
```

Details zu Datenmodell, Terminberechnung, Idempotenz, CSV-Import-Logik und Entscheidungsgründen: siehe [docs/plan.md](docs/plan.md).

## Lizenz

AGPL-3.0-or-later
