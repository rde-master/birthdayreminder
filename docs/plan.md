# Nextcloud-App "Geburtstagserinnerung" (birthdayreminder)

Dieses Dokument beschreibt die Architektur und die Entscheidungsgründe hinter den einzelnen Bausteinen der App — die Kurzfassung für Nutzer steht in [README.md](../README.md).

## Kontext

Eine Gruppe oder Organisation möchte automatisch daran erinnert werden, wenn Mitglieder Geburtstag haben: konfigurierbare Erinnerungs-Mails an Verantwortliche zu frei einstellbaren Vorlaufzeiten (z.B. 30/14/2/1 Tage vorher + am Tag selbst), plus eine Glückwunsch-Mail direkt an das Mitglied.

Statt eines externen Skripts mit eigenem Cronjob läuft das Ganze als **native Nextcloud-App**: sie nutzt Nextclouds eigenen Background-Job-Mechanismus (kein neuer Cron-Eintrag nötig) und den bereits konfigurierten Mailer (kein separates SMTP). Als Bonus gibt es eine Geburtstags-Übersicht als Dashboard-Widget direkt in Nextcloud.

Wer als „Admin für die Geburtstagserinnerung" gilt, wird über zwei feste Nextcloud-Gruppen gesteuert (siehe „Zugriffsrechte über zwei feste Gruppen" unten) — nicht über volle Nextcloud-Systemadmin-Rechte. Verantwortliche haben eigene Nextcloud-Konten; die Erinnerungs-Mails nutzen deren dort hinterlegte E-Mail-Adresse.

Zusätzlich zur reinen Terminerinnerung: Für **runde Geburtstage** (frei definierbare Jubiläumsalter, z.B. 18/30/50/60/70) kann der Admin je Alter einen Geschenk-Vorschlagstext hinterlegen, der dann in der Erinnerungs-Mail an die Verantwortlichen erscheint. Jeder Empfänger kann für sich wählen, ob er Erinnerungen für *alle* Geburtstage oder nur für die runden bekommen möchte. Die Glückwunsch-Mail ans Mitglied selbst ist vom Admin mit Platzhaltern (Name, Alter, …) frei im Text editierbar; Mitglieder ohne hinterlegte E-Mail-Adresse bekommen diese Mail naturgemäß nicht (die Erinnerungs-Mails an die Verantwortlichen sind davon unabhängig und gehen trotzdem raus).

App-ID: `birthdayreminder`. Ziel-Kompatibilität: Nextcloud ≥ 28.

## Eigenes Mitgliederregister statt Nextcloud-Adressbuch

**Warum:** Die Nextcloud-Contacts-App selbst stellte sich für diesen Zweck als zu unübersichtlich heraus. Der ursprüngliche Ansatz (Kontakte direkt über die CardDAV-Schicht lesen, Adressbuch-Auswahl in den Admin-Einstellungen) wurde daher durch ein **eigenes, app-internes Mitgliederregister** ersetzt, das die App selbst pflegt.

**Datenmodell:** Tabelle `oc_birthdayreminder_member`:

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| first_name | STRING(255) | Vorname |
| last_name | STRING(255) | Nachname |
| birth_day | INTEGER | |
| birth_month | INTEGER | |
| birth_year | INTEGER, nullable | unbekanntes Geburtsjahr wird unterstützt |
| email | STRING(255), nullable | |
| disabled | BOOLEAN, default false | deaktiviert = keine Mails mehr, weder Erinnerung noch Glückwunsch |
| remark | TEXT, nullable | „Bemerkung" |
| created_at / updated_at | INTEGER | |

`ReminderService`/`DebugUpcoming` lesen über `MemberMapper::findAllActive()`; `MemberMapper::toModelMember()`-Konvertierung baut daraus das `Model\Member`-Wertobjekt, das `ReminderCalculator`, `MailService` etc. verwenden.

**Eigene Seite in der Nextcloud-Menüleiste** (`PageController` + `templates/main.php` + `js/members.js`, dynamisch registriert — siehe „Zugriffsrechte" unten): Übersicht, Mitgliederliste, Import/Export, Geschenke, Logs.

## CSV- und Kontakte-Import

`lib/Service/CsvParser.php`, `MemberSyncPlanner.php`, `CsvImportService.php`, `lib/Contacts/ContactsGateway.php`:

- **CSV**: Spalten-Zuordnung passiert **client-seitig** — die Datei wird im Browser per `FileReader` gelesen, Kopfzeile geparst, der Admin ordnet die Spalten den Feldern Vorname/Nachname/Geburtsdatum (Pflicht) und E-Mail (optional) per Dropdown zu. Erst der fertige Import-Request (Inhalt + Mapping) geht ans Backend.
- **Kontakte**: `ContactsGateway` liest über die öffentliche `OCP\Contacts\IManager`-API alle eigenen Adressbücher des aktuellen Nutzers (außer dem Systemadressbuch) und wandelt sie in dieselbe Zeilenform um wie der CSV-Import — dadurch teilen sich beide Importwege die komplette Diff/Apply-Logik (`CsvImportService::applyParsedRows()`).
- **Abgleich-Logik** (`MemberSyncPlanner`, geteilt von beiden Importwegen): primär per **E-Mail-Adresse** (case-insensitive) — die stabilere Identität, verhindert Duplikate auch wenn sich ein Anzeigename geändert hat. Fällt auf Namensvergleich (Vorname+Nachname, ohne Groß-/Kleinschreibung) zurück, wenn keine E-Mail vorhanden ist. Neuer Eintrag → anlegen; bekannter Eintrag mit geänderten Werten → aktualisieren; bekannter Eintrag unverändert → nichts tun; bekannter Eintrag fehlt in der Quelle → deaktivieren, mit Bemerkung „Deaktiviert da bei Import nicht mehr vorhanden" (wird an eine vorhandene Bemerkung angehängt statt sie zu überschreiben, und nicht doppelt eingetragen bei wiederholtem Import).
- **Bewusst konservativ:** Ein bereits deaktiviertes Mitglied wird beim Import **nie automatisch wieder aktiviert**, selbst wenn es wieder auftaucht — das bleibt eine manuelle Entscheidung auf der Mitgliederseite, damit ein Import nicht versehentlich eine bewusste manuelle Deaktivierung aufhebt.
- `MemberSyncPlanner::plan()` ist reine, DB-freie Logik (Diffing anhand einfacher Arrays) und dadurch ohne Nextcloud-Runtime testbar; `CsvImportService` lädt den aktuellen Bestand, ruft den Planner auf und wendet den Plan über `MemberMapper` an.
- Geburtsdatum-Formate (CSV): `TT.MM.JJJJ`, `TT.MM.` (kein Jahr bekannt) und ISO `JJJJ-MM-TT`. Für Kontakte übernimmt `VCardDate` die Konvertierung zum/vom vCard-BDAY-Rohformat (`JJJJMMTT` bzw. `--MMTT` ohne Jahr).
- **Kontakte-Export** (`ContactsGateway::exportMembersToUserContacts()`): schreibt aktive Mitglieder in das erste beschreibbare, nicht-geteilte Adressbuch des Nutzers. Bewusst werden nur flache vCard-Properties gesetzt (FN, BDAY, EMAIL) — `IAddressBook::createOrUpdate()` kann strukturierte Properties wie `N` nicht korrekt setzen (im Sabre\VObject-Quellcode verifiziert: ein Array-Wert wird dort als mehrere Property-Instanzen statt als eine strukturierte behandelt), daher bleibt `N` unbeschrieben. Abgleich bestehender Kontakte ebenfalls primär per E-Mail, sonst per Name.
- **CSV-Export** (`CsvExporter`, reine Serialisierung): Mitgliederliste und Versand-Log lassen sich als CSV herunterladen; für die Mitgliederliste gibt es zusätzlich eine leere Vorlage mit Beispielzeilen zum direkten Download.

Eine Beispiel-CSV liegt unter [docs/beispiel-mitglieder-import.csv](beispiel-mitglieder-import.csv).

## Zeitplan, manueller Versand, Versand-Log

**Konfigurierbare tägliche Prüfzeit** (`ConfigService::getDailyRunTime()`, Default 08:00): `DailyReminderJob` (TimedJob) prüft **stündlich** statt einmal täglich, führt den eigentlichen Versand aber weiterhin nur einmal pro Tag aus. Die Entscheidung "ist die eingestellte Uhrzeit erreicht und wurde heute noch nicht gelaufen?" übernimmt `lib/Service/ScheduleGate.php` — eine reine, DB-freie Klasse (Eingabe: konfigurierte Zeit, aktueller Zeitpunkt, letztes Lauf-Datum), dadurch ohne Nextcloud-Runtime testbar. Nach einem tatsächlichen Lauf wird `last_run_date` (`ConfigService`) auf heute gesetzt.

**Manueller Sofort-Versand:** `ReminderService::run()` ist in `runReminders()` und `runCongrats()` aufgeteilt (gemeinsame `buildContext()`-Berechnung), sodass die Admin-Buttons „Erinnerungen jetzt versenden" / „Glückwünsche jetzt versenden" nur die jeweilige Hälfte auslösen — beide nutzen dieselbe Idempotenz-Log-Tabelle wie der automatische Lauf, ein manueller Trigger kann also nie zu doppelten Mails führen (weder untereinander noch gegenüber dem geplanten Lauf).

**Erinnerungen/Glückwünsche global deaktivierbar:** `ConfigService::getRemindersEnabled()`/`getCongratsEnabled()` (Default: beide `true`) werden am Anfang von `runReminders()`/`runCongrats()` geprüft — ist eine der beiden aus, wird der jeweilige Lauf komplett übersprungen (kein Kontext-Aufbau, keine Mails), unabhängig davon, ob er automatisch oder manuell ausgelöst wurde. Empfänger, Vorlaufzeiten und Mitglieder bleiben dabei unangetastet. Die beiden Methoden geben jetzt `bool` zurück (statt `void`), damit der manuelle „jetzt versenden"-Button in der Admin-UI korrekt „übersprungen, da deaktiviert" statt fälschlich „erledigt" anzeigen kann.

**Versand-Log einsehbar, exportierbar & löschbar:** `ReminderLogMapper::findRecent()` liefert die letzten 200 Einträge; `MembersApiController::getSendLog()` löst `contact_uid` zu Mitgliedsnamen auf (Fallback „Unbekannt/gelöscht" für inzwischen entfernte Mitglieder), `exportSendLogCsv()` liefert dieselben Daten als CSV-Download. Admin-Einstellungsseite hat einen „Log löschen"-Button (`ReminderLogMapper::deleteAll()`) mit Bestätigungsdialog, der explizit auf das Duplikat-Risiko hinweist (Löschen hebt die Idempotenz-Sperre für den restlichen Tag auf).

## Mail-Format: schlichter Klartext

`MailService` nutzt bewusst **reinen Klartext** (`IMessage::setPlainBody()`) statt einer HTML-Vorlage — ein früherer Versuch mit `OCP\Mail\IEMailTemplate` hinterließ auch ohne Header/Footer-Aufruf noch sichtbares HTML-Tabellen-Gerüst (Logo, Leerbereiche), das sich über die Vorlagen-API nicht vollständig entfernen ließ.

- Absender-Anzeigename fest auf „Geburtstagserinnerung" gesetzt (`Util::getDefaultEmailAddress('no-reply')` für die Adresse selbst, damit `mail_from_address`/`mail_domain` weiterhin respektiert werden — nur der Anzeigename weicht vom Instanz-Theming-Namen ab).
- Erinnerungs-Mail-**Text** nennt Geburtsdatum, Wochentag (`lib/Service/GermanDate.php`, reine Wochentag-Übersetzung ohne intl-Abhängigkeit) und Alter; ist kein Geburtsjahr hinterlegt, steht explizit „Alter unbekannt" statt es wegzulassen. Der **Betreff** nennt standardmäßig nur das Alter (`MailService::reminderSubject()`); pro Empfänger lässt sich `Recipient::birthdateInSubject` (Admin-UI: Checkbox „Geburtsdatum im Betreff" unter dem Umfang-Dropdown, Default aus, Spalte via Migration `Version1030Date20260827190000`) zuschalten, dann wird `targetDate->format('d.m.')` an den Alters-Teil angehängt.
- Platzhalter für die admin-editierbare Glückwunsch-Vorlage: `{name}`, `{vorname}`, `{alter}`, `{datum}`, `{wochentag}`.

## Mitgliederseite als Sidebar-Navigation mit Übersicht/Diagrammen

Die Mitgliederseite (`js/members.js`) ist als Layout mit linker Seitenleiste aufgebaut: oben ein Link zu „Persönliche Einstellungen", darunter die Navigation (Übersicht/Mitgliederliste/Import-Export/Geschenke/Logs), unten ein Link zu „Admin-Einstellungen". Rechts daneben ein Content-Bereich, der das jeweils aktive Panel zeigt (`renderLayout()`/`activate()`); Mitgliederliste und Logs laden ihre Daten erst beim ersten Aufruf des jeweiligen Panels.

Die **Übersicht** zeigt drei Spalten (heute / nächste 7 Tage / nächste 30 Tage — nicht überlappend, via `ReminderService::getUpcomingBirthdaysWithinDays()` und `MembersApiController::getOverview()`) sowie zwei reine SVG-Diagramme ohne externe Bibliothek: ein Kreisdiagramm „Geburtstage pro Monat" und ein Balkendiagramm „Altersstruktur" (10er-Jahrgangs-Buckets, erste Bucket 0–10 elf Werte breit, danach 11–20/21–30/…; Mitglieder ohne Geburtsjahr landen sichtbar in einer eigenen „unbekannt"-Säule statt stillschweigend zu fehlen). Die Alters-Bucketing-Logik ist als reine, testbare Methoden `ReminderCalculator::currentAge()`/`ageBucketIndex()` implementiert.

## Zugriffsrechte über zwei feste Gruppen

Zwei feste, namentlich vorgegebene Gruppen steuern den Zugriff:

- **„Geburtstagserinnerung Verantwortliche"** — nur das Mitgliederregister (Übersicht/Mitgliederliste/Import-Export/Geschenke/Logs)
- **„Geburtstagserinnerung Admin"** — zusätzlich die Admin-Einstellungen (Empfänger/Meilensteine/Mail-Vorlage/Zeitplan)
- echte Nextcloud-Systemadmins haben immer beides
- alle anderen Nutzer sehen nichts, auch keinen Menüeintrag

Umgesetzt über zwei getrennte `IDelegatedSettings`-Klassen: `AdminSettings` (die echte, sichtbare Admin-Einstellungsseite, nur an „Geburtstagserinnerung Admin" delegiert) und `MemberAreaAccess` (permission-only, keine eigene Seite — gleiches Muster wie `apps/webhook_listeners`, siehe Kommentar in `appinfo/info.xml` — an beide Gruppen delegiert). `PageController`/`MembersApiController` prüfen gegen `MemberAreaAccess::class`, `AdminApiController` gegen `AdminSettings::class`.

Eine Migration (`Version1020Date20260827120000`) legt beide Gruppen beim `app:enable`/Upgrade automatisch (idempotent) über `IGroupManager::createGroup()` an; die eigentliche `occ admin-delegation:add`-Zuordnung bleibt bewusst ein manueller Schritt (siehe README.md).

Der Menüeintrag wird **nicht statisch** über `<navigations>` in `info.xml` deklariert (das wäre für jeden eingeloggten Nutzer sichtbar gewesen), sondern dynamisch über einen `LoadAdditionalEntriesEvent`-Listener (`lib/Listener/LoadNavigationEntryListener.php`, registriert in `Application::register()`) — nur wenn der Nutzer Systemadmin oder Mitglied einer der beiden Gruppen ist, wird `INavigationManager::add()` aufgerufen. Wichtige Falle dabei: ein erster Versuch, das in `Application::boot()` zu prüfen, scheiterte, weil `boot()` läuft, bevor die Anmeldung des laufenden Requests aufgelöst ist — `IUserSession::getUser()` lieferte dort immer `null`. Das Vorbild für den korrekten Ansatz ist `apps/app_api`'s `LoadMenuEntriesListener`.

## Terminberechnung, Meilenstein-Filter & Idempotenz

`ReminderCalculator` ist eine reine, ohne I/O testbare Klasse: für jeden Offset eines Empfängers wird `heute + N Tage` berechnet und mit Monat/Tag jedes Mitglieds verglichen (behandelt Jahreswechsel automatisch). 29. Februar in Nicht-Schaltjahren wird auf 28. Februar abgebildet.

Wenn ein Empfänger `only_milestones = true` gesetzt hat, wird der Treffer zusätzlich gefiltert: das erreichte Alter am Zieltag (`Zieljahr - Geburtsjahr`) muss in der Meilenstein-Tabelle vorkommen. Das setzt ein bekanntes Geburtsjahr voraus — bei Mitgliedern ohne Jahr kann kein Alter und damit kein „rund" bestimmt werden; solche Mitglieder werden für `only_milestones`-Empfänger übersprungen.

Ist ein Treffer ein Meilenstein-Jahr, wird der zugehörige `gift_text` aus der Meilenstein-Tabelle geladen und in `ReminderService`/`MailService` an die Erinnerungs-Mail angehängt (unabhängig davon, ob der jeweilige Empfänger `only_milestones` gesetzt hat oder nicht — wer alle Geburtstage abonniert hat, sieht den Geschenk-Hinweis bei runden Geburtstagen genauso).

Damit ein doppelt laufender Job (Webcron-Doppelauslösung, manueller `occ`-Aufruf) keine doppelten Mails verschickt, wird vor jedem Versand in einer Log-Tabelle geprüft, ob für `(mitglieds_id, erinnerungs_typ, tage_vorher, jahr)` schon gesendet wurde; danach wird der Versand dort protokolliert. Ein Unique-Index sichert das zusätzlich gegen Races ab.

## Datenmodell: Empfänger, Vorlaufzeiten, Meilensteine, Log

**`oc_birthdayreminder_recipient`** — Empfänger-Stammdaten (ein Datensatz pro Person/Gruppe/E-Mail, unabhängig von den Offsets):

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| recipient_type | STRING(16) | `user` \| `group` \| `email` |
| recipient_value | STRING(255) | NC-User-ID / Gruppen-ID / E-Mail |
| only_milestones | BOOLEAN, default false | true = nur Erinnerungen zu runden Geburtstagen |
| birthdate_in_subject | BOOLEAN, default false | true = Geburtsdatum zusätzlich im Betreff der Erinnerungs-Mail |
| created_at | INTEGER | |

Unique-Index auf `(recipient_type, recipient_value)`.

**`oc_birthdayreminder_offset`** — welche Vorlauftage ein Empfänger hat:

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT PK | |
| recipient_id | BIGINT, FK → recipient.id | |
| days_before | INTEGER | 0 = Tag des Geburtstags |

Unique-Index auf `(recipient_id, days_before)`. Ein Empfänger kann beliebig viele Zeilen haben — die Anzahl ist damit von Natur aus variabel.

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
| contact_uid | STRING(255) | Mitglieds-ID (stabil, auch bei Namensänderung) |
| reminder_type | STRING(16) | `offset` (an Verantwortliche) \| `congrats` (ans Mitglied) |
| days_before | INTEGER, nullable | nur bei `offset` |
| birthday_year | INTEGER | |
| sent_at | INTEGER | |

Empfängertypen `user`/`group`/`email` werden beim Versand zu einer deduplizierten Menge von E-Mail-Adressen aufgelöst (`IUserManager`/`IGroupManager`), damit niemand doppelt Post bekommt, wenn er sowohl direkt als auch über eine Gruppe zugeordnet ist.

## Einstellungen: Persönlich und Admin

Zwei getrennte Einstellungsseiten (`js/settings-personal.js` / `js/settings-admin.js`, Vanilla JS ohne Build-Schritt), gleiche Recipient-Tabelle, unterschiedlicher Wirkungsbereich:

**Grundprinzip Empfänger:** Verantwortliche haben ein eigenes Nextcloud-Benutzerkonto — das ist der primäre, vorgesehene Weg (`recipient_type='user'`), die Erinnerungs-Mail geht an die im NC-Benutzerkonto hinterlegte E-Mail-Adresse (`IUserManager::get($uid)->getEMailAddress()`). `group`/`email` bleiben als Zusatzoptionen bestehen (z.B. eine Sammel-Mailadresse oder eine Person ganz ohne NC-Konto), sind aber der Nebenfall.

**Persönliche Einstellungen** (`PersonalSettings implements ISettings`, Bereich `personal` — jeder eingeloggte Nextcloud-Nutzer sieht das unter Einstellungen → Geburtstagserinnerung): Der Nutzer verwaltet dort seinen eigenen `oc_birthdayreminder_recipient`-Datensatz (`recipient_type='user'`, `recipient_value = <eigene UID>`, per Upsert automatisch angelegt) mit seiner eigenen Liste von Vorlauftagen und dem Umschalter „Nur runde Geburtstage" ⇄ „Alle Geburtstage" (`only_milestones`).

`PersonalApiController` erlaubt nur Lese-/Schreibzugriff auf den Recipient-Datensatz der eigenen UID (kein Admin-Recht nötig, aber strikt auf sich selbst beschränkt).

Die Admin-Einstellungsseite enthält zusätzlich:
1. Die volle Tabelle aller Empfänger (inkl. `group`- und `email`-Empfänger, die keine eigene persönliche Einstellungsseite haben) mit ihren Offsets und dem „Nur runde Geburtstage"-Schalter
2. **Runde Geburtstage / Geschenke**: Liste von (Alter, Geschenktext)-Paaren, frei bearbeitbar
3. **Glückwunsch-Mail-Vorlage**: Betreff- und Textfeld mit Platzhaltern, Speicherung via `IAppConfig` (`congrats_subject_template`/`congrats_body_template`), Ersetzung zur Versandzeit über `MailTemplateRenderer`
4. **Zeitplan & manueller Versand** (siehe oben)

## Dashboard-Widget

`BirthdayWidget implements IAPIWidgetV2` liefert die nächsten anstehenden Geburtstage (gleiche Berechnungslogik wie der Background-Job, über `ReminderService` geteilt) — dank `IAPIWidgetV2` ohne eigenes Frontend-JS, die Dashboard-App rendert die Liste generisch.

## Mailversand

`MailService` nutzt `OCP\Mail\IMailer` — dadurch automatisch über die in Nextcloud bereits konfigurierte SMTP-Transportroute, keine eigenen Zugangsdaten nötig. Zwei Methoden:

- `sendReminder(...)` — an Verantwortliche, unabhängig davon, ob das Mitglied selbst eine E-Mail-Adresse hat. Ist ein Geschenkvorschlag hinterlegt (Treffer = Meilenstein-Alter), wird er angehängt.
- `sendCongratulation(...)` — ans Mitglied, **nur wenn eine E-Mail-Adresse hinterlegt ist**; `ReminderService` prüft das vorher und überspringt (mit Log-Eintrag) Mitglieder ohne E-Mail-Adresse, ohne dass das den Versand der Verantwortlichen-Erinnerungen beeinflusst. Betreff/Text werden über `MailTemplateRenderer::render($template, [...])` aus den admin-konfigurierten Vorlagen erzeugt.

## Entwicklung & Test

PHPUnit deckt die reine, DB-freie Logik ab (Terminberechnung, CSV-/Datums-Parsing, Import-Abgleich, Alters-Bucketing) und läuft ohne Nextcloud-Runtime — siehe [README.md](../README.md#entwicklung). Alles, was echte Nextcloud-Services braucht (Controller, Mailversand, Kontakte-Zugriff, Datenbank-Mapper), wird manuell gegen eine laufende Nextcloud-Instanz verifiziert: App aktivieren (`occ app:enable`, führt die Migration aus), Testmitglieder mit Geburtstag = heute±Offset anlegen, den Background-Job manuell anstoßen (`occ background-job:list` + nächster Cron-Tick, oder `occ background-job:execute <id>`) und den tatsächlichen Mailversand kontrollieren — inklusive eines zweiten Laufs am selben Tag, um Idempotenz zu bestätigen.
