# Geburtstagserinnerung (birthdayreminder)

Nextcloud-App für Vereine: liest die Geburtstage der Mitglieder aus einem gemeinsamen Nextcloud-Adressbuch und verschickt

- **Erinnerungs-Mails an Verantwortliche** zu frei konfigurierbaren Vorlaufzeiten (z.B. 30/14/2/1 Tage vorher + am Tag selbst)
- **eine Glückwunsch-Mail direkt ans Mitglied**, sofern eine E-Mail-Adresse hinterlegt ist
- optional einen **Geschenkvorschlag** bei runden Geburtstagen (frei definierbare Jubiläumsalter)
- eine Übersicht der nächsten Geburtstage als **Dashboard-Widget**

Läuft komplett auf dem Nextcloud-Server selbst (eigener Background-Job, nutzt den bereits konfigurierten Mailer) — kein externes Skript, kein separater Cron-Eintrag, kein Node/Build-Schritt auf dem Server nötig.

## Status

| Meilenstein | Beschreibung | Status |
|---|---|---|
| M1 | Kontakte lesen + Terminberechnung | ✅ fertig, verifiziert |
| M2 | Persistenz + Erinnerungs-Mails | ✅ fertig, verifiziert |
| M3 | Glückwunsch-Mail ans Mitglied | ✅ fertig, verifiziert |
| M4 | Admin-Einstellungsseite (Gruppen-Delegation) | ✅ fertig, verifiziert |
| M4b | Persönliche Einstellungsseite | ✅ fertig, verifiziert |
| M5 | Dashboard-Widget | ✅ fertig, verifiziert |
| M6 | Deploy auf die echte Vereins-Instanz | ⏳ offen |

„Verifiziert" heißt: Ende-zu-Ende live gegen eine echte Nextcloud-34-Testinstanz getestet (Mailversand, Idempotenz, Gruppen-Delegation, Dashboard-API) — siehe [docs/plan.md](docs/plan.md) für die Details.

## Voraussetzungen

- Nextcloud ≥ 28 (getestet gegen 34.0.1)
- Apps `contacts` und `dav` aktiviert
- Ein gemeinsames Adressbuch mit den Vereinsmitgliedern (Geburtsdatum + E-Mail-Adresse je Kontakt)
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

1. **Einstellungen → Verwaltung → Geburtstagserinnerung** öffnen (als Nextcloud-Admin)
2. Adressbuch auswählen (Besitzer-Benutzername eingeben → laden → Adressbuch wählen → speichern)
3. Empfänger anlegen: Nextcloud-Nutzer, Gruppe oder feste E-Mail-Adresse, mit eigenen Vorlaufzeiten
4. Optional: runde Geburtstage mit Geschenkvorschlag hinterlegen
5. Optional: Text der Glückwunsch-Mail anpassen (Platzhalter `{name}`, `{vorname}`, `{alter}`, `{datum}`)

Damit auch Nicht-Systemadmins (z.B. der Vorstand) Zugriff auf die Admin-Einstellungsseite bekommen, ohne volle Nextcloud-Admins zu sein:

```bash
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\AdminSettings" <gruppen-id>
```

Jeder Nextcloud-Nutzer kann außerdem unter **Einstellungen → Geburtstagserinnerung** selbst festlegen, zu welchen Vorlaufzeiten (bzw. nur bei runden Geburtstagen) er erinnert werden möchte.

## Entwicklung

```bash
composer install     # PHPUnit für die lokalen Tests
vendor/bin/phpunit    # reine Terminlogik (Schaltjahre, Jahreswechsel, BDAY-Parsing) - läuft ohne Nextcloud-Runtime
```

Es gibt keinen Frontend-Build-Schritt: `js/` und `css/` sind handgeschriebenes Vanilla JS/CSS, direkt einsatzbereit (bewusste Abweichung vom ursprünglichen Plan, der Vue/Webpack vorsah — siehe [docs/plan.md](docs/plan.md)).

**Wichtig bei Änderungen an `js/`/`css/`:** Nextcloud hängt an jede Datei einen Cache-Buster (`?v=...`) an, der aus der `<version>` in `appinfo/info.xml` berechnet wird — nicht aus dem Dateiinhalt. Nach jeder JS/CSS-Änderung muss die Version dort erhöht werden, sonst liefern Browser die alte, gecachte Datei aus.

## Architektur (Kurzfassung)

```
appinfo/          info.xml, routes.php
lib/
  AppInfo/        Application.php (DI, Dashboard-Widget-Registrierung)
  BackgroundJob/  DailyReminderJob.php (täglicher Lauf)
  Contacts/       ContactsGateway.php (liest Adressbuch via CardDavBackend, parst BDAY)
  Model/          Member.php
  Db/             Recipient/Offset/Milestone/ReminderLog + zugehörige Mapper
  Service/        ReminderCalculator (reine Terminlogik), ReminderService (Orchestrierung),
                  MailService, RecipientResolver, MailTemplateRenderer, ConfigService
  Controller/      AdminApiController, PersonalApiController
  Settings/        AdminSection/AdminSettings (IDelegatedSettings), PersonalSection/PersonalSettings
  Dashboard/       BirthdayWidget.php (IAPIWidgetV2)
  Migration/       Datenbank-Schema (4 Tabellen)
  Command/         occ-Befehle für Debug/Verwaltung
js/, css/, templates/   Vanilla-JS-Einstellungsseiten (kein Build-Schritt)
tests/Unit/             PHPUnit-Tests für die reine Terminlogik
docs/plan.md            ursprünglicher Architektur-/Umsetzungsplan
```

Details zu Datenmodell, Terminberechnung, Idempotenz und Entscheidungsgründen: siehe [docs/plan.md](docs/plan.md).

## Lizenz

AGPL-3.0-or-later
