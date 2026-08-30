# Geburtstagserinnerung (birthdayreminder)

Nextcloud-App: verwaltet die Mitglieder einer Gruppe oder Organisation in einem eigenen Mitgliederregister und verschickt

- **Erinnerungs-Mails an Verantwortliche** zu frei konfigurierbaren Vorlaufzeiten (z.B. 30/14/2/1 Tage vorher + am Tag selbst)
- **eine Glückwunsch-Mail direkt ans Mitglied**, sofern eine E-Mail-Adresse hinterlegt ist
- optional einen **Geschenkvorschlag** bei runden Geburtstagen (frei definierbare Jubiläumsalter)
- eine Übersicht der nächsten Geburtstage als **Dashboard-Widget** sowie auf der Mitgliederseite selbst (inkl. Kreisdiagramm „Geburtstage pro Monat" und Balkendiagramm „Altersstruktur")

Läuft komplett auf dem Nextcloud-Server selbst (eigener Background-Job, nutzt den bereits konfigurierten Mailer) — kein externes Skript, kein separater Cron-Eintrag, kein Node/Build-Schritt auf dem Server nötig.

Die Mitgliederdaten (Vorname, Nachname, Geburtsdatum, E-Mail, Deaktiviert-Schalter, Bemerkung) liegen in einem **eigenen Mitgliederregister** der App — nicht in den Nextcloud-Kontakten, auch wenn ein Import aus bzw. Export in die eigenen Kontakte möglich ist (siehe unten). Das Register hat eine eigene Seite in der Nextcloud-Menüleiste.

## Funktionsübersicht

- **Mitgliederseite** mit eigenem Icon in der Nextcloud-Menüleiste, gegliedert in Übersicht, Mitgliederliste, Import/Export, Geschenke und Logs
- **Übersicht**: anstehende Geburtstage in drei Zeiträumen (heute / nächste 7 Tage / nächste 30 Tage) sowie ein Kreisdiagramm „Geburtstage pro Monat" und ein Balkendiagramm „Altersstruktur"
- **Import/Export**: CSV-Import mit Spaltenzuordnung (inkl. Download-Vorlage) und CSV-Export der Mitgliederliste; Import aus und Export in die eigenen Nextcloud-Kontakte, Abgleich primär per E-Mail-Adresse, damit keine doppelten Einträge entstehen
- **Geschenke**: schreibgeschützte Übersicht der Geschenkvorschläge zu runden Geburtstagen (Bearbeitung bleibt der Admin-Seite vorbehalten)
- **Versand-Log**: Protokoll der letzten 200 tatsächlich verschickten Mails, als CSV exportierbar, vom Admin löschbar
- **Konfigurierbare Vorlaufzeiten** pro Empfänger (Nextcloud-Nutzer, Gruppe oder feste E-Mail-Adresse) — zentral über die Admin-Seite oder von jedem Nutzer selbst über die persönlichen Einstellungen
- **Runde Geburtstage** mit admin-konfigurierbarem Geschenkvorschlag, der automatisch in die Erinnerungs-Mail übernommen wird
- **Admin-editierbare Glückwunsch-Mail-Vorlage** mit Platzhaltern (Name, Alter, Datum, Wochentag)
- **Konfigurierbare tägliche Prüfzeit** sowie ein manueller Sofort-Versand für Erinnerungen und Glückwünsche, unabhängig voneinander auslösbar
- **Erinnerungs- bzw. Glückwunsch-Mails jeweils komplett deaktivierbar**, ohne Empfänger, Vorlaufzeiten oder Mitglieder zu verändern
- **Externe, token-geschützte Cron-Trigger-URL** als Alternative zu Nextclouds eigener Job-Warteschlange (siehe [Externer Cron-Aufruf](#externer-cron-aufruf))
- **Dashboard-Widget** mit den nächsten anstehenden Geburtstagen
- **Zwei feste Zugriffsgruppen**, unabhängig von vollen Nextcloud-Systemadmin-Rechten (siehe [Zugriffsrechte](#zugriffsrechte))

## Voraussetzungen

- Nextcloud ≥ 28
- SSH- bzw. `occ`-Zugriff für Installation und Konfiguration

## Installation

```bash
# App-Verzeichnis in Nextclouds apps/ (oder custom_apps/) klonen
git clone <repo-url> apps/birthdayreminder

# aktivieren (führt automatisch die Datenbank-Migration aus)
php occ app:enable birthdayreminder
```

Die App registriert sich automatisch im vorhandenen Nextcloud-Cron (`cron.php`) — es ist **kein zusätzlicher Cron-Job** nötig.

## Update

```bash
cd apps/birthdayreminder
git pull

# fuehrt eine eventuell neue Datenbank-Migration aus (z.B. neue Tabellen/Spalten)
php occ app:disable birthdayreminder
php occ app:enable birthdayreminder
```

Bestehende Daten und Einstellungen (Mitglieder, Empfänger, Vorlaufzeiten, Vorlagen, Zeitplan, Zugriffsgruppen-Zuordnung) bleiben beim Update erhalten — `app:disable`/`app:enable` löscht nichts, sondern prüft nur, ob eine der Migrationen unter `lib/Migration/` noch aussteht und führt sie aus.

Empfehlenswert vor größeren Updates: ein Datenbank-Backup der Nextcloud-Instanz (Standard-Nextcloud-Praxis, nicht app-spezifisch). Bei Datenbank-Problemen nach einem Update hilft `php occ app:disable birthdayreminder` als erster Schritt, um die App vorübergehend aus dem Cron/Seitenaufbau zu nehmen, ohne Daten zu verlieren.

## Einrichtung

1. **Mitgliederregister** (eigenes Icon oben in der Nextcloud-Menüleiste, sichtbar für berechtigte Nutzer — siehe [Zugriffsrechte](#zugriffsrechte)) öffnen: dort **Übersicht**, **Mitgliederliste**, **Import/Export**, **Geschenke** und **Logs** über die linke Seitenleiste
2. **Einstellungen → Verwaltung → Geburtstagserinnerung** öffnen (als Nextcloud-Admin oder Mitglied der Gruppe „Geburtstagserinnerung Admin"): Empfänger anlegen (Nextcloud-Nutzer, Gruppe oder feste E-Mail-Adresse) mit eigenen Vorlaufzeiten
3. Optional: runde Geburtstage mit Geschenkvorschlag hinterlegen
4. Optional: Text der Glückwunsch-Mail anpassen (Platzhalter `{name}`, `{vorname}`, `{alter}`, `{datum}`, `{wochentag}`)
5. Im Bereich „Zeitplan & manueller Versand": Uhrzeit für die tägliche Prüfung einstellen (Standard 08:00, wird in der **Ortszeit des Servers** interpretiert — also z.B. 06:00 tatsächlich 06:00 Uhr lokal, nicht UTC), sowie je ein Schalter, um Erinnerungs- bzw. Glückwunsch-Mails komplett zu deaktivieren (z.B. während einer Testphase) — betrifft automatischen wie manuellen Versand gleichermaßen, ohne Empfänger/Vorlaufzeiten/Mitglieder zu verändern. Dort auch zwei Buttons, um Erinnerungen bzw. Glückwünsche sofort manuell auszulösen (respektiert dieselbe Versand-Historie wie der automatische Lauf, also keine doppelten Mails), sowie ein Button zum vollständigen Löschen des Versand-Logs (Warnung: hebt die Duplikat-Sperre für den Tag auf)

Jeder Nextcloud-Nutzer kann außerdem unter **Einstellungen → Geburtstagserinnerung** selbst festlegen, zu welchen Vorlaufzeiten (bzw. nur bei runden Geburtstagen) er erinnert werden möchte.

### Zugriffsrechte

Zwei feste Nextcloud-Gruppen steuern den Zugriff — beim ersten `app:enable` automatisch angelegt (leer, Mitglieder selbst über Einstellungen → Benutzer hinzufügen):

| Gruppe | Mitgliederregister (Übersicht/Liste/Import-Export/Geschenke/Logs) | Admin-Einstellungen (Empfänger/Meilensteine/Mail-Vorlage/Zeitplan) |
|---|---|---|
| `Geburtstagserinnerung Verantwortliche` | ✅ | ❌ |
| `Geburtstagserinnerung Admin` | ✅ | ✅ |
| echte Nextcloud-Systemadmins | ✅ | ✅ |
| alle anderen Nutzer | ❌ (auch kein Menüeintrag sichtbar) | ❌ |

Die Gruppen werden zwar automatisch angelegt, die eigentliche Berechtigung muss aber einmalig zugewiesen werden:

```bash
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\MemberAreaAccess" "Geburtstagserinnerung Verantwortliche"
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\MemberAreaAccess" "Geburtstagserinnerung Admin"
php occ admin-delegation:add "OCA\BirthdayReminder\Settings\AdminSettings" "Geburtstagserinnerung Admin"
```

### Externer Cron-Aufruf

Nextclouds eigener Hintergrund-Job-Mechanismus verarbeitet pro Aufruf oft nur **einen** von vielen fälligen Jobs aller installierten Apps. Bei Hosting-Anbietern, die als Cronjob nur einen URL-Aufruf erlauben statt eines echten Kommandozeilen-Crons (z.B. All-Inkl), kann das dazu führen, dass die tägliche Prüfung durch die Konkurrenz mit Dutzenden anderer Jobs stark verzögert wird, statt zur eingestellten Uhrzeit zu laufen.

Im Bereich „Externer Cron-Aufruf" der Admin-Einstellungen steht dafür eine eigene, mit einem geheimen Token abgesicherte URL bereit (`GET /apps/birthdayreminder/cron-trigger/<token>`). Sie prüft direkt und **unabhängig von der eingestellten „Täglichen Prüfzeit"**, ob heute schon versendet wurde, und verschickt andernfalls sofort — die Zeitsteuerung übernimmt dabei der Zeitpunkt, den du beim Hosting-Anbieter für den Cronjob selbst einstellst. Richte dort einen Cronjob ein, der diese URL regelmäßig aufruft (z.B. alle 5–15 Minuten). Die normale, interne Prüfung (siehe oben) bleibt davon unberührt zusätzlich aktiv; beide zusammen sind unproblematisch, da doppelter Versand am selben Tag immer verhindert wird (dieselbe Versand-Historie wie beim automatischen Lauf). Über „Token neu generieren" lässt sich die URL invalidieren, falls sie z.B. versehentlich geteilt wurde — der Cronjob beim Hosting-Anbieter muss danach mit der neuen URL aktualisiert werden.

### Import/Export

Auf der Mitgliederseite unter **Import/Export**:

- **CSV-Import**: Datei auswählen, Spalten den Feldern **Vorname**, **Nachname**, **Geburtsdatum** (Pflicht) und **E-Mail** (optional) zuordnen, dann importieren. Über „Vorlage herunterladen" gibt es eine leere CSV mit korrektem Spaltenkopf und zwei Beispielzeilen; alternativ liegt eine Beispieldatei unter [docs/beispiel-mitglieder-import.csv](docs/beispiel-mitglieder-import.csv) (Semikolon-getrennt, Geburtsdatum als `TT.MM.JJJJ`; `TT.MM.` ohne Punkt am Ende geht auch, wenn kein Jahr bekannt ist).
- **CSV-Export**: lädt die komplette Mitgliederliste (inkl. deaktivierter Mitglieder) im selben Format herunter.
- **Import aus Kontakten**: liest alle eigenen Nextcloud-Adressbücher (außer dem Systemadressbuch) und übernimmt Name, Geburtsdatum und E-Mail-Adresse.
- **Export in Kontakte**: schreibt alle aktiven Mitglieder in das erste beschreibbare, eigene Adressbuch.

Abgleich-Logik bei jedem Import (CSV wie Kontakte) — zuerst per **E-Mail-Adresse** (damit ein geänderter Anzeigename keine doppelten Einträge erzeugt), sonst per Namensvergleich (Vorname+Nachname, ohne Berücksichtigung von Groß-/Kleinschreibung):

- **neuer Eintrag** → Mitglied wird angelegt
- **bekannter Eintrag, Daten geändert** → Mitglied wird aktualisiert
- **bekannter Eintrag, keine Änderung** → nichts passiert
- **bekannter Eintrag fehlt in der Quelle** → Mitglied wird deaktiviert, Bemerkung „Deaktiviert da bei Import nicht mehr vorhanden" wird ergänzt

Ein bereits deaktiviertes Mitglied wird **nicht automatisch wieder aktiviert**, nur weil es wieder auftaucht — das bleibt bewusst eine manuelle Entscheidung auf der Mitgliederseite.

### Mail-Format

Alle Mails sind bewusst einfacher reiner Text — kein Nextcloud-Logo, keine Fußzeile, kein HTML-Vorlagen-Gerüst (`OCP\Mail\IEMailTemplate` erzeugt sonst einen großen Banner samt Restlayout, das auch ohne Header/Footer noch sichtbare Leerbereiche hinterlässt). Absender-Anzeigename ist „Geburtstagserinnerung" statt des Instanz-Theming-Namens; die eigentliche Absenderadresse kommt weiterhin aus der Nextcloud-Mailkonfiguration.

Die Erinnerungs-Mail an Verantwortliche nennt im Text automatisch Geburtsdatum, Wochentag und (falls bekannt) das Alter; ist kein Geburtsjahr hinterlegt, steht explizit „Alter unbekannt" statt es wegzulassen. Der Betreff nennt standardmäßig nur das Alter — pro Empfänger lässt sich unter „Umfang" zusätzlich „Geburtsdatum im Betreff" aktivieren (standardmäßig aus), dann steht das Datum auch dort.

## Entwicklung

```bash
composer install     # PHPUnit für die lokalen Tests
vendor/bin/phpunit    # reine Logik (Terminberechnung, CSV-/Datums-Parsing, Import-Abgleich) - läuft ohne Nextcloud-Runtime
```

Es gibt keinen Frontend-Build-Schritt: `js/` und `css/` sind handgeschriebenes Vanilla JS/CSS, direkt einsatzbereit.

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
  Contacts/       ContactsGateway.php (Import aus/Export in die eigenen Nextcloud-Kontakte, ueber OCP\Contacts\IManager)
  Service/        ReminderCalculator (reine Terminlogik inkl. Alters-Bucketing), ReminderService (Orchestrierung),
                  MailService, RecipientResolver, MailTemplateRenderer, ConfigService,
                  CsvParser/MemberSyncPlanner (reine CSV-/Kontakte-Import-Logik), CsvImportService (Orchestrierung),
                  CsvExporter (reine CSV-Serialisierung), VCardDate (reine BDAY-Formatkonvertierung),
                  ScheduleGate (reine Logik: "ist die eingestellte Tageszeit erreicht?"),
                  GermanDate (reine Logik: deutsche Wochentagsnamen),
                  ClockService ("jetzt"/"heute" in der echten Server-Zeitzone statt Nextclouds UTC-Laufzeit-Default)
  Controller/      PageController (Mitgliederseite), MembersApiController, AdminApiController, PersonalApiController,
                   CronTriggerController (oeffentliche, token-geschuetzte Alternative zu Nextclouds Job-Warteschlange)
  Settings/        AdminSection/AdminSettings (IDelegatedSettings, nur "Geburtstagserinnerung Admin"),
                   MemberAreaSection/MemberAreaAccess (permission-only, beide Gruppen), PersonalSection/PersonalSettings
  Dashboard/       BirthdayWidget.php (IAPIWidgetV2)
  Migration/       Datenbank-Schema (5 Tabellen) + automatisches Anlegen der beiden Zugriffsgruppen
  Command/         occ-Befehle für Debug/Verwaltung
js/, css/, templates/   Vanilla-JS-Seiten (Mitgliederregister, Einstellungen) - kein Build-Schritt
tests/Unit/             PHPUnit-Tests für die reine Logik
docs/plan.md            Architektur-Hintergrund und Entscheidungsgründe
docs/beispiel-mitglieder-import.csv   Beispieldatei für den CSV-Import
```

Details zu Datenmodell, Terminberechnung, Idempotenz, Import-Logik und Entscheidungsgründen: siehe [docs/plan.md](docs/plan.md).

## Lizenz

AGPL-3.0-or-later
