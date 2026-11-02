<div align="center">
<img src="docs/logo.svg" width="120" alt="openwehr"/>

# openwehr
### Die Web-App für die Freiwillige Feuerwehr

[![Website](https://img.shields.io/badge/Website-openwehr.de-c8102e)](https://www.openwehr.de)
[![Wiki](https://img.shields.io/badge/Doku-Wiki-1b2645)](https://github.com/roberteinsle/openwehr/wiki)
[![Release](https://img.shields.io/github/v/release/roberteinsle/openwehr?label=Version)](https://github.com/roberteinsle/openwehr/releases)
[![Lizenz](https://img.shields.io/badge/Lizenz-AGPL--3.0-blue)](LICENSE)

</div>

---

openwehr bringt die Abläufe einer Freiwilligen Feuerwehr aufs Handy: Fahrzeuge buchen, Termine und Rückmeldungen, Einsatzberichte, Atemschutzüberwachung, Kleiderkammer, Mitteilungen und vieles mehr – in einer App, auf dem eigenen Server, ohne Lizenzkosten. Die Anmeldung läuft ohne Passwort über einen Link per E-Mail, Oberfläche und E-Mails sind deutsch.

## Installation & Schnellstart

openwehr läuft als fertiges Docker-Image (`ghcr.io/roberteinsle/openwehr`) mit einer PostgreSQL-Datenbank. Gebraucht werden ein kleiner Server (ab **2 CPU-Kernen, 4 GB RAM, 50 GB Speicher**), eine Domain und ein Postfach zum Versenden der Mails. Programmieren muss niemand.

Die **[Schritt-für-Schritt-Anleitung im Wiki](https://github.com/roberteinsle/openwehr/wiki/Installation)** führt in etwa einer Stunde durch alles:

1. Server bei **UpCloud** anlegen (Rechenzentrum in Frankfurt) – über den [Empfehlungslink](https://signup.upcloud.com/?promo=R5F4K3) gibt es 25 € Startguthaben, und das Projekt bekommt eine kleine Gutschrift.
2. **Coolify** als Verwaltungsoberfläche installieren und die Domain auf den Server zeigen lassen.
3. **PostgreSQL** und **openwehr** in Coolify anlegen, Umgebungsvariablen eintragen ([Konfiguration](https://github.com/roberteinsle/openwehr/wiki/Konfiguration)).
4. Mit der Adresse aus `ADMIN_EMAILS` anmelden, Abteilungen, Funktionen und Fahrzeuge anlegen, Module einschalten.

Updates sind ein neuer Image-Tag in Coolify; die Datenbank wird beim Start automatisch angepasst. Vorher immer eine Sicherung machen.

## So funktioniert's

openwehr besteht aus einem **Kern** und **Modulen**. Der Kern bringt Anmeldung, Kameraden mit Abteilungen und Funktionen, die Startseite, Benachrichtigungen (Glocke, Push aufs Handy, E-Mail) und den Admin-Bereich. Jedes Modul ist nach der Installation ausgeschaltet; die Wehr schaltet ein, was sie braucht, und gibt es allen oder einzelnen Kameraden frei. Rollen wie Kleiderwart oder Atemschutzwart werden je Modul vergeben – ohne dass jemand Admin sein muss.

- **Für das Handy gemacht** und als App auf den Startbildschirm zu legen, mit Push-Benachrichtigungen.
- **Module arbeiten zusammen:** Fahrzeuge, Kameraden, Abteilungen und Orte gibt es nur einmal. Der Einsatzbericht zeigt die nächsten Hydranten und Objektpläne, Werkstattzeiten sperren die Fahrzeugbuchung, Übungsdienste stehen im Kalender.
- **Eigene Daten bleiben bei der Wehr:** Betrieb auf dem eigenen Server, Gesundheitsdaten verschlüsselt, keine Weitergabe an Dritte.

## Module

| Modul | Wofür |
|---|---|
| [Fahrzeugbuchung](https://github.com/roberteinsle/openwehr/wiki/Fahrzeugbuchung) | Fahrzeuge im gemeinsamen Kalender reservieren, Entscheidung bei Überschneidungen, Wachen-Monitor |
| [Kalender & Termine](https://github.com/roberteinsle/openwehr/wiki/Kalender-und-Termine) | Übungen, Dienste und Veranstaltungen mit Zielgruppe, Rückmeldung, Anwesenheit und Kalender-Abo |
| [Übungsdienstplaner](https://github.com/roberteinsle/openwehr/wiki/Übungsdienstplaner) | Wer welchen Übungsdienst mit welchem Thema übernimmt, mit Tausch und Fahrzeugbuchung |
| [Schichtplan](https://github.com/roberteinsle/openwehr/wiki/Schichtplan) | Arbeitsdienste, Feste und Stände: Schichten verteilen oder selbst eintragen lassen |
| [Mitteilungen](https://github.com/roberteinsle/openwehr/wiki/Mitteilungen) | Nachrichten der Wehrführung mit Anhängen und Lesebestätigung |
| [Einsatzbericht](https://github.com/roberteinsle/openwehr/wiki/Einsatzbericht) | Einsätze erfassen und freigeben, Besatzung, DIVERA-Anbindung, Lagemeldungen, Jahresbericht |
| [Atemschutzüberwachung](https://github.com/roberteinsle/openwehr/wiki/Atemschutzüberwachung) | Trupps mit Uhr, Drücken und Warnungen, Geräteträger mit G26.3 und Übungen |
| [Gefahrstoffe](https://github.com/roberteinsle/openwehr/wiki/Gefahrstoffe) | Warntafel und Gefahrenmatrix nachschlagen, auch ohne Netz |
| [Expositionen](https://github.com/roberteinsle/openwehr/wiki/Expositionen) | Gefahrstoff-Expositionen erfassen, 40 Jahre aufbewahren, Meldeliste für die ZED |
| [Wasserkarte & Hydranten](https://github.com/roberteinsle/openwehr/wiki/Wasserkarte-und-Hydranten) | Löschwasserquellen auf der Karte, im Einsatz die nächsten zur Einsatzstelle |
| [Objektpläne](https://github.com/roberteinsle/openwehr/wiki/Objektpläne-und-Brandschutzunterlagen) | Feuerwehrpläne je Objekt, bei Alarm die Pläne der Einsatzstelle, offline verfügbar |
| [Fahrzeugverwaltung & Flotte](https://github.com/roberteinsle/openwehr/wiki/Fahrzeugverwaltung-und-Flotte) | Fahrzeugakte, Status, Werkstattzeiten, Fristen wie HU/SP/UVV |
| [Fahrtenbuch](https://github.com/roberteinsle/openwehr/wiki/Fahrtenbuch) | Fahrten mit Kilometerstand am Handy, Tanken, Auswertung und Export |
| [Führerscheine](https://github.com/roberteinsle/openwehr/wiki/Führerscheine) | Führerscheinkontrolle per Foto, wer welches Fahrzeug fahren darf |
| [Ausrüstungsverwaltung](https://github.com/roberteinsle/openwehr/wiki/Ausrüstungsverwaltung) | Geräte, Prüffristen, Ausleihe, Beladeplan, Inventur per QR-Code und NFC |
| [Wartungsmanagement](https://github.com/roberteinsle/openwehr/wiki/Wartungsmanagement) | Prüfungen nach Vorlage mit unterschriebenem Protokoll als PDF |
| [Mängelmanagement](https://github.com/roberteinsle/openwehr/wiki/Mängelmanagement) | Mängel per QR-Code melden und bis zur Erledigung verfolgen |
| [Kleiderkammer](https://github.com/roberteinsle/openwehr/wiki/Kleiderkammer) | Lager, Ausgabe der Bekleidung, Protokoll und Inventar als PDF und Excel |
| [Schlüsselverwaltung](https://github.com/roberteinsle/openwehr/wiki/Schlüsselverwaltung) | Schlüssel und Transponder mit Ausgabe gegen Unterschrift |
| [Personalverwaltung](https://github.com/roberteinsle/openwehr/wiki/Personalverwaltung) | Diensthistorie, Dienstzeit und Ehrungen, digitaler Dienstausweis, Dienstbeteiligung |
| [Lehrgänge & Qualifikationen](https://github.com/roberteinsle/openwehr/wiki/Lehrgänge-und-Qualifikationen) | Interesse an Lehrgängen melden, Lehrgangsplan, Qualifikationen mit Auffrischung |
| [Unterweisungen](https://github.com/roberteinsle/openwehr/wiki/Unterweisungen) | Unterweisungen mit Fristen und Unterschrift auf dem Tablet |
| [Verbandbuch & Vorfälle](https://github.com/roberteinsle/openwehr/wiki/Verbandbuch-und-Vorfälle) | Verletzungen und Vorfälle nach DGUV dokumentieren, verschlüsselt |
| [Budget & Finanzen](https://github.com/roberteinsle/openwehr/wiki/Budget-und-Finanzen) | Ausgaben mit Beleg und Genehmigung, Budgets, Jahresabschluss |

Alles Weitere – jede Seite, jede Rolle, jede Einstellung – steht im **[Wiki](https://github.com/roberteinsle/openwehr/wiki)**.

## Datenschutz & Sicherheit

openwehr läuft auf dem Server der Wehr; Daten gehen an niemanden sonst. Es gibt keine Telemetrie, und die Seiten sind für Suchmaschinen gesperrt. Gesundheitsdaten (Verbandbuch, Expositionen) sind verschlüsselt gespeichert, Änderungen stehen im Protokoll des Admin-Bereichs.

Jede Änderung am Code läuft durch alle Tests, statische Analyse und Prüfungen auf bekannte Schwachstellen; Updates der Abhängigkeiten kommen wöchentlich. Sicherheitslücken bitte vertraulich melden, wie in [SECURITY.md](SECURITY.md) beschrieben.

## Mitmachen

Ideen, Wünsche und Fehler sind willkommen – am besten als [Issue](https://github.com/roberteinsle/openwehr/issues/new/choose) mit dem Ablauf, wie er in der Wehr heute läuft.

**Für Entwickler:** Laravel 12, Livewire 4, Filament 5, Tailwind 4, PostgreSQL 18, Tests mit Pest. Das Repository öffnet sich fertig eingerichtet im Dev-Container bzw. in GitHub Codespaces (mit Beispieldaten, Anmeldung als `admin@example.com`, der Login-Link steht in `storage/logs/laravel.log`); `composer test` startet die Tests. Hinweise zur Architektur stehen in [CLAUDE.md](CLAUDE.md), die vollständige Beschreibung aller Funktionen im [Handbuch](HANDBUCH.md), aus dem das Wiki erzeugt wird.

## Links

- **Website:** [www.openwehr.de](https://www.openwehr.de)
- **Wiki:** [Dokumentation und Installationsanleitung](https://github.com/roberteinsle/openwehr/wiki)
- **Versionen:** [Releases](https://github.com/roberteinsle/openwehr/releases)
- **YouTube:** [@openwehr](https://www.youtube.com/@openwehr)
- **Facebook:** [openwehr](https://www.facebook.com/openwehr)

## Lizenz

openwehr ist freie Software unter der [GNU Affero General Public License v3.0](LICENSE). Jede Wehr darf openwehr kostenlos nutzen, anpassen und weitergeben. Wer eine veränderte Version anderen über das Netz anbietet, muss deren Quelltext ebenfalls unter der AGPL-3.0 offenlegen.
