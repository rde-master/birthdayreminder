# Nextcloud-App "Geburtstagserinnerung" (birthdayreminder)

## Kontext

Ein Verein möchte automatisch daran erinnert werden, wenn Mitglieder Geburtstag haben: konfigurierbare Erinnerungs-Mails an Verantwortliche zu frei einstellbaren Vorlaufzeiten (z.B. 30/14/2/1 Tage vorher + am Tag selbst), plus eine Glückwunsch-Mail direkt an das Mitglied. Die Mitgliederdaten (Name, Geburtsdatum, E-Mail) liegen bereits als Kontakte in einem gemeinsamen Nextcloud-Adressbuch. Nextcloud läuft bei all-inkl (Shared/Managed Hosting), SSH-Zugriff ist vorhanden.

Statt eines externen Skripts mit eigenem Cronjob wird das Ganze als **native Nextcloud-App** gebaut: Sie liest die Kontakte direkt, nutzt Nextclouds eigenen Background-Job-Mechanismus (kein neuer Cron-Eintrag nötig) und den bereits konfigurierten Mailer (kein separates SMTP). Als Bonus gibt es eine Geburtstags-Übersicht als Dashboard-Widget direkt in Nextcloud.

Wer als „Admin für die Geburtstagserinnerung" gilt, wird über eine frei wählbare Nextcloud-Gruppe (z.B. „Vorstand") gesteuert — nicht über volle Nextcloud-Systemadmin-Rechte. Verantwortliche haben eigene Nextcloud-Konten; die Erinnerungs-Mails nutzen deren dort hinterlegte E-Mail-Adresse.

Zusätzlich zur reinen Terminerinnerung: Für **runde Geburtstage** (frei definierbare Jubiläumsalter, z.B. 18/30/50/60/70) kann der Admin je Alter einen Geschenk-Vorschlagstext hinterlegen, der dann in der Erinnerungs-Mail an die Verantwortlichen erscheint. Jeder Empfänger kann für sich wählen, ob er Erinnerungen für *alle* Geburtstage oder nur für die runden bekommen möchte. Die Glückwunsch-Mail ans Mitglied selbst ist vom Admin mit Platzhaltern (Name, Alter, …) frei im Text editierbar; Mitglieder ohne hinterlegte E-Mail-Adresse bekommen diese Mail naturgemäß nicht (die Erinnerungs-Mails an die Verantwortlichen sind davon unabhängig und gehen trotzdem raus).

App-ID: `birthdayreminder`. Ziel-Kompatibilität: Nextcloud ≥ 28 (aktuell genug für moderne APIs wie das Dashboard-Widget ohne eigenes JS, alt genug um auf dem all-inkl-Server sicher vorhanden zu sein).

> **Hinweis:** Dies ist der ursprünglich abgestimmte Plan (Stand M1-Freigabe). Die tatsächliche Umsetzung wich an einer Stelle bewusst ab: Statt Vue/Webpack (siehe unten) wurde für die Einstellungsseiten **Vanilla JS ohne Build-Schritt** verwendet, um auf dem Shared-Hosting-Server keine Node-Toolchain zu benötigen. Den aktuellen Umsetzungsstand beschreibt die [README.md](../README.md) im Projekt-Root.

## Architektur-Überblick

```
birthdayreminder/
├── appinfo/{info.xml, routes.php}
├── lib/
│   ├── AppInfo/Application.php          # IBootstrap: DI, Widget-Registrierung
│   ├── BackgroundJob/DailyReminderJob.php  # TimedJob, täglich
│   ├── Contacts/ContactsGateway.php     # liest Adressbuch via CardDavBackend, parst BDAY
│   ├── Model/Member.php
│   ├── Db/{Recipient,RecipientMapper,Offset,OffsetMapper,Milestone,MilestoneMapper,ReminderLog,ReminderLogMapper}.php
│   ├── Service/{ReminderCalculator,ReminderService,MailService,RecipientResolver,MailTemplateRenderer}.php
│   ├── Controller/{AdminApiController,PersonalApiController}.php   # AJAX für Settings-UIs
│   ├── Settings/{AdminSection,AdminSettings,PersonalSettings}.php
│   ├── Dashboard/BirthdayWidget.php     # IAPIWidgetV2
│   └── Migration/Version1000Date...php  # legt 4 DB-Tabellen an
├── src/                                 # Vue-Frontend für Admin- und persönliche Einstellungsseite
│   ├── settings-admin.js
│   ├── settings-personal.js
│   └── components/{OffsetList,RecipientForm,MilestoneList,CongratsEmailEditor}.vue
├── css/, templates/, l10n/de.json
└── package.json, composer.json (meist keine externen PHP-Deps nötig)
```

### Kontakte lesen (Adressbuch/BDAY/EMAIL)

Nicht `OCP\Contacts\IManager` (braucht eine Nutzer-Session, funktioniert nicht im Background-Job). Stattdessen `OCA\DAV\CardDAV\CardDavBackend` direkt injizieren und die vCards mit `Sabre\VObject` parsen — das ist der übliche Weg, um ohne Login-Kontext auf Kontakte zuzugreifen. `ContactsGateway` kapselt das, damit nur eine Stelle angepasst werden muss, falls sich das ändert.

BDAY-Parsing muss beide vCard-Formen abdecken: `--MMDD` (kein Jahr bekannt) und `YYYYMMDD`/`YYYY-MM-DD` (mit Jahr). Unparsebare Werte werden geloggt und übersprungen.

Das Adressbuch wird einmalig in den Einstellungen ausgewählt: Admin gibt den Besitzer-Benutzernamen des gemeinsamen Adressbuchs ein, eine Dropdown-Liste von dessen Adressbüchern wird per AJAX geladen; die gewählte `addressBookId` wird über `IAppConfig` gespeichert.

### Terminberechnung, Meilenstein-Filter & Idempotenz

`ReminderCalculator` ist eine reine, ohne I/O testbare Klasse: für jeden Offset eines Empfängers wird `heute + N Tage` berechnet und mit Monat/Tag jedes Mitglieds verglichen (behandelt Jahreswechsel automatisch). 29. Februar in Nicht-Schaltjahren wird auf 28. Februar abgebildet.

Wenn ein Empfänger `only_milestones = true` gesetzt hat, wird der Treffer zusätzlich gefiltert: das erreichte Alter am Zieltag (`Zieljahr - Geburtsjahr`) muss in der Meilenstein-Tabelle vorkommen. Das setzt ein bekanntes Geburtsjahr voraus — bei Kontakten ohne Jahr (`--MMDD`) kann kein Alter und damit kein „rund" bestimmt werden; solche Mitglieder werden für `only_milestones`-Empfänger übersprungen (und das wird beim Anlegen des Kontakts/Testens in der Admin-UI als Hinweis angezeigt, keine stille Fehlfunktion).

Ist ein Treffer ein Meilenstein-Jahr, wird der zugehörige `gift_text` aus der Meilenstein-Tabelle geladen und in `ReminderService`/`MailService` an die Erinnerungs-Mail angehängt (unabhängig davon, ob der jeweilige Empfänger `only_milestones` gesetzt hat oder nicht — wer alle Geburtstage abonniert hat, sieht den Geschenk-Hinweis bei runden Geburtstagen genauso).

Damit ein doppelt laufender Job (Webcron-Doppelauslösung, manueller `occ`-Aufruf) keine doppelten Mails verschickt, wird vor jedem Versand in einer Log-Tabelle geprüft, ob für `(kontakt_uid, erinnerungs_typ, tage_vorher, jahr)` schon gesendet wurde; danach wird der Versand dort protokolliert. Ein Unique-Index sichert das zusätzlich gegen Races ab.

### Datenmodell (4 Tabellen, per Migration)

**`oc_birthdayreminder_recipient`** — Empfänger-Stammdaten (ein Datensatz pro Person/Gruppe/E-Mail, unabhängig von den Offsets):
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| recipient_type | STRING(16) | `user` \| `group` \| `email` |
| recipient_value | STRING(255) | NC-User-ID / Gruppen-ID / E-Mail |
| only_milestones | BOOLEAN, default false | true = nur Erinnerungen zu runden Geburtstagen |
| created_at | INTEGER | |

Unique-Index auf `(recipient_type, recipient_value)`.

**`oc_birthdayreminder_offset`** — welche Vorlauftage ein Empfänger hat:
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| recipient_id | BIGINT, FK → recipient.id | |
| days_before | INTEGER | 0 = Tag des Geburtstags |

Unique-Index auf `(recipient_id, days_before)`. Ein Empfänger kann beliebig viele Zeilen haben (Empfänger A nur `30`, Empfänger B `30`+`14`+`10` usw.) — die Anzahl ist damit von Natur aus variabel.

**`oc_birthdayreminder_milestone`** — konfigurierte runde Geburtstage + Geschenkvorschlag:
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| age | INTEGER, unique | z.B. 18, 30, 50, 60, 70 |
| gift_text | TEXT | z.B. „Tankgutschein über 30 €" |

**`oc_birthdayreminder_log`** — Versand-Historie / Idempotenz:
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| contact_uid | STRING(255) | vCard-UID (stabil, auch bei Namensänderung) |
| reminder_type | STRING(16) | `offset` (an Verantwortliche) \| `congrats` (ans Mitglied) |
| days_before | INTEGER, nullable | nur bei `offset` |
| birthday_year | INTEGER | |
| sent_at | INTEGER | |

Empfängertypen `user`/`group`/`email` werden beim Versand zu einer deduplizierten Menge von E-Mail-Adressen aufgelöst (`IUserManager`/`IGroupManager`), damit niemand doppelt Post bekommt, wenn er sowohl direkt als auch über eine Gruppe zugeordnet ist.

### Einstellungen: Persönlich (User) + Admin (Vue, Standard-NC-Muster)

Zwei getrennte Einstellungsseiten, gleiche Tabelle, unterschiedlicher Wirkungsbereich:

**Grundprinzip Empfänger:** Verantwortliche haben ein eigenes Nextcloud-Benutzerkonto — das ist der primäre, vorgesehene Weg (`recipient_type='user'`), die Erinnerungs-Mail geht an die im NC-Benutzerkonto hinterlegte E-Mail-Adresse (`IUserManager::get($uid)->getEMailAddress()`). `group`/`email` bleiben als Zusatzoptionen bestehen (z.B. eine Sammel-Mailadresse oder eine Person ganz ohne NC-Konto), sind aber der Nebenfall.

**Persönliche Einstellungen** (`PersonalSettings implements ISettings`, Bereich `personal` statt `admin` — jeder eingeloggte Nextcloud-Nutzer sieht das unter Einstellungen → Geburtstagserinnerung): Der Nutzer verwaltet dort seinen eigenen `oc_birthdayreminder_recipient`-Datensatz (`recipient_type='user'`, `recipient_value = <eigene UID>`, per Upsert automatisch angelegt) mit:
- seiner eigenen Liste von Vorlauftagen (z.B. „30, 14")
- einem Umschalter „Nur runde Geburtstage" ⇄ „Alle Geburtstage" (setzt `only_milestones`)

`PersonalApiController` erlaubt nur Lese-/Schreibzugriff auf den Recipient-Datensatz der eigenen UID (kein Admin-Recht nötig, aber strikt auf sich selbst beschränkt).

**Admin vs. Nutzer wird über eine Nextcloud-Gruppe gesteuert, nicht über System-Admin-Rechte:** Die App-Admin-Einstellungsseite nutzt Nextclouds **Delegated Admin Settings** (`AdminSettings implements IDelegatedSettings` statt nur `ISettings`, verfügbar seit NC 25, passt zur Ziel-Version ≥28). Ein echter Nextcloud-Systemadministrator legt dabei einmalig über die Standard-Nextcloud-Oberfläche (Einstellungen → Verwaltung → Freigaben für Verwaltungseinstellungen) fest, welche Gruppe(n) — z.B. eine Gruppe „Vorstand" — Zugriff auf die Geburtstagserinnerung-Admin-Seite bekommen. Mitglieder dieser Gruppe gelten dann als „Admin für die Geburtstagserinnerung", ohne volle Nextcloud-Systemadmins sein zu müssen. Die AJAX-Endpunkte in `AdminApiController` sind entsprechend mit dem PHP-Attribut `#[AuthorizedAdminSetting(settings: AdminSettings::class)]` abgesichert (Standardmechanismus für delegierte Einstellungen), statt manuell `IGroupManager::isAdmin()` zu prüfen — dadurch gilt dieselbe Gruppen-Freigabe einheitlich für Seite und API.

Die Admin-Einstellungsseite selbst enthält zusätzlich:
1. Adressbuch-Auswahl (Besitzer eingeben → Dropdown laden → Verbindungstest zeigt Kontakt-/BDAY-Anzahl)
2. Die volle Tabelle aller Empfänger (inkl. `group`- und `email`-Empfänger, die keine eigene persönliche Einstellungsseite haben) mit ihren Offsets und dem „Nur runde Geburtstage"-Schalter — Admin kann hier stellvertretend das tun, was ein Nutzer auch selbst in seinen persönlichen Einstellungen tun könnte
3. **Runde Geburtstage / Geschenke**: Liste von (Alter, Geschenktext)-Paaren, frei bearbeitbar (`MilestoneList.vue` gegen `oc_birthdayreminder_milestone`)
4. **Glückwunsch-Mail-Vorlage**: Ein Editor (`CongratsEmailEditor.vue`, Betreff- + Textfeld) für den Inhalt der Mail, die ans Mitglied selbst geht, mit Platzhaltern wie `{name}`, `{vorname}`, `{alter}`, `{datum}` — Speicherung als zwei Strings (`congrats_subject_template`, `congrats_body_template`) via `IAppConfig`. Ersetzung der Platzhalter zur Versandzeit übernimmt `MailTemplateRenderer`.

Beide UIs teilen sich die `OffsetList.vue`-Komponente (persönliche Ansicht: nur Offset-Liste + Meilenstein-Schalter für den eigenen Datensatz, kein Empfängertyp wählbar, da implizit „ich selbst"; Admin-Ansicht: volle Tabelle mit Empfängertyp-Auswahl), Speichern jeweils über die passende API.

### Dashboard-Widget

`BirthdayWidget implements IAPIWidgetV2` liefert die nächsten anstehenden Geburtstage (gleiche Berechnungslogik wie der Background-Job, über `ReminderService` geteilt) — dank `IAPIWidgetV2` ohne eigenes Frontend-JS, die Dashboard-App rendert die Liste generisch.

### Mailversand

`MailService` nutzt `OCP\Mail\IMailer` + `IEMailTemplate` — dadurch automatisch über die in Nextcloud bereits konfigurierte SMTP-Transportroute, keine eigenen Zugangsdaten nötig. Zwei Methoden:

- `sendReminder(string $toEmail, Member $member, int $daysBefore, ?string $giftText)` — an Verantwortliche. Ist `$giftText` gesetzt (Treffer = Meilenstein-Alter), wird ein zusätzlicher Absatz mit dem Geschenkvorschlag eingefügt. Wird unabhängig davon verschickt, ob das Mitglied selbst eine E-Mail-Adresse hat.
- `sendCongratulation(string $toEmail, Member $member)` — ans Mitglied, **nur wenn `$member->email` vorhanden ist**; `ReminderService` prüft das vorher und überspringt (mit Log-Eintrag) Mitglieder ohne E-Mail-Adresse, ohne dass das den Versand der Verantwortlichen-Erinnerungen beeinflusst. Betreff/Text kommen nicht mehr aus Code, sondern werden über `MailTemplateRenderer::render($template, [...])` aus den admin-konfigurierten Vorlagen (`congrats_subject_template`/`congrats_body_template`, s. Admin-Einstellungen) erzeugt; ein einfacher `str_replace('{platzhalter}', ...)`-Renderer genügt.

### Entwicklung & Test

Statt einer lokalen Docker-Instanz wird direkt gegen eine **separate Test-Nextcloud-Instanz bei all-inkl** entwickelt (vom Nutzer bereitgestellt, per SSH erreichbar). Ablauf pro Änderung:

1. Code lokal schreiben, PHPUnit-Tests für reine Logik (`ReminderCalculator`, BDAY-Parsing) lokal ohne Nextcloud-Runtime laufen lassen (Schaltjahre, Jahreswechsel, Kontakte ohne Geburtsjahr).
2. App-Code per `rsync`/`scp` über SSH auf die Test-Instanz übertragen (in `apps/` bzw. `custom_apps/`).
3. Vue-Frontend lokal bauen (`npm run build`), kompiliertes JS mit ausliefern — kein Node auf dem Server nötig.
4. Auf der Test-Instanz per SSH: `occ app:enable birthdayreminder` (führt Migration automatisch aus, registriert Background-Job), Testkontakte mit Geburtstag = heute±Offset im Test-Adressbuch anlegen, Job manuell per `occ background-job:execute <id>` oder `occ background-job:list` gefolgt vom nächsten Cron-Tick anstoßen, Mailversand kontrollieren (bei einer echten Instanz gehen Mails über die dort konfigurierte SMTP-Route — ggf. eine Test-Empfängeradresse nutzen).
5. Nach Verifikation auf der Testinstanz: gleiches Deployment (Schritt 2–4, `occ app:enable`) auf der echten Vereins-Instanz. Kein neuer Cron-Eintrag nötig — der Job läuft über den ohnehin vorhandenen Nextcloud-Cron mit.

Sobald der Nutzer Zugangsdaten (SSH-Host, ggf. Pfad zur Nextcloud-Installation) zur Testinstanz bereitstellt, kann direkt darauf entwickelt/getestet werden.

## Bau-Reihenfolge (Meilensteine)

- **M1** — Kontakte lesen + Terminberechnung, nur `occ`-Debug-Befehl der Treffer auf der Konsole ausgibt (kein DB/Mail). Auf der Test-Instanz gegen echte/testweise angelegte Vereinskontakte validiert.
- **M2** — Persistenz + echter Versand der Verantwortlichen-Erinnerungen: Migration (alle 4 Tabellen), Mapper, `ReminderService`, `MailService`, `DailyReminderJob`. Konfiguration vorerst per `occ config`/SQL, noch ohne UI.
- **M3** — Glückwunsch-Mail ans Mitglied (gleicher Job-Lauf, gleiche Idempotenz-Logik mit `reminder_type='congrats'`; Mitglieder ohne E-Mail werden übersprungen).
- **M4** — Admin-Einstellungsseite als Delegated Admin Setting (`IDelegatedSettings`, Gruppen-Freigabe z.B. für „Vorstand"): Adressbuch-Auswahl, Empfänger/Offset-Verwaltung inkl. „Nur runde Geburtstage"-Schalter, Meilenstein/Geschenk-Liste, Glückwunsch-Mail-Editor mit Platzhaltern (löst manuelle Config-Bearbeitung aus M2 ab).
- **M4b** — Persönliche Einstellungsseite, über die jeder Nutzer seine eigenen Vorlauftage + Meilenstein-Schalter selbst verwaltet (teilt sich Komponente/Backend-Tabellen mit M4).
- **M5** — Dashboard-Widget.
- **M6** — Deploy auf den echten all-inkl-Vereins-Server, `occ app:enable`, erster echter Cron-Durchlauf verifizieren, Mailversand (inkl. Meilenstein-Text und Platzhalter-Ersetzung) kontrollieren.

## Kritische Dateien
- `lib/Contacts/ContactsGateway.php`
- `lib/Service/ReminderCalculator.php` (inkl. Meilenstein-/Alter-Filterlogik)
- `lib/Service/ReminderService.php`
- `lib/Service/MailTemplateRenderer.php` (Platzhalter-Ersetzung für die Glückwunsch-Mail)
- `lib/BackgroundJob/DailyReminderJob.php`
- `lib/Migration/Version1000Date20260101000000.php` (4 Tabellen)
- `lib/Controller/AdminApiController.php` / `lib/Controller/PersonalApiController.php`
- `lib/Settings/AdminSettings.php` / `lib/Settings/PersonalSettings.php`
- `appinfo/info.xml`

## Verifikation
- M1: `occ`-Debug-Befehl auf der Test-Instanz gegen das Test-Adressbuch laufen lassen, Ausgabe manuell mit erwarteten Geburtstagen abgleichen.
- PHPUnit für `ReminderCalculator` (Jahreswechsel, Schaltjahr, Kontakt ohne Geburtsjahr) und BDAY-Regex-Parsing — lokal, ohne Nextcloud-Runtime.
- M2/M3: Auf der Test-Instanz Testkontakte mit Geburtstag = heute±Offset anlegen, Job manuell per `occ background-job:execute` anstoßen, tatsächlichen Mailversand an eine Test-Adresse prüfen — inkl. zweitem Lauf am selben Tag, um Idempotenz zu bestätigen.
- M4/M4b/M5: Admin-Einstellungsseite, persönliche Einstellungsseite (mit zweitem Testnutzer prüfen, dass er nur seine eigenen Zeilen sieht/ändern kann) und Dashboard-Widget im Browser gegen die Test-Instanz durchklicken. Gezielt einen Testkontakt mit Meilenstein-Alter (z.B. wird heute+30 Tage 18) anlegen und prüfen, dass der Geschenktext in der Erinnerungs-Mail auftaucht, ein `only_milestones`-Empfänger nur bei diesem Treffer benachrichtigt wird, und die Glückwunsch-Mail mit korrekt ersetzten Platzhaltern sowie ein Testkontakt ganz ohne E-Mail-Adresse (keine Glückwunsch-Mail, aber Verantwortliche werden trotzdem informiert) verifizieren.
- M6: Nach Deploy auf die echte Vereins-Instanz `occ migrations:status birthdayreminder` und `occ background-job:list` prüfen, dann einen echten Cron-Zyklus abwarten und Mailzustellung an Verantwortliche und Testmitglied kontrollieren.
