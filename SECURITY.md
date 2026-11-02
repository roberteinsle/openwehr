# Sicherheit

openwehr verarbeitet Daten von Feuerwehrangehörigen, zum Teil Gesundheitsdaten. Sicherheitslücken bitte **nicht** als öffentliches Issue melden.

## Eine Lücke melden

Bitte über **GitHub → Security → „Report a vulnerability“** in diesem Repository melden (private Sicherheitsmeldung). Hilfreich sind:

- was betroffen ist (Modul, Seite, Version aus dem Admin-Bereich unten links),
- wie sich die Lücke nachvollziehen lässt,
- was ein Angreifer damit erreichen kann.

Bitte keine echten Daten einer Wehr in die Meldung schreiben und keine fremden Instanzen angreifen – zum Nachvollziehen reicht eine eigene Testinstanz (`docker compose up --build`).

## Was danach passiert

- Eingangsbestätigung innerhalb von 7 Tagen.
- Bestätigte Lücken werden vorrangig behoben und als eigenes Release veröffentlicht (z. B. `v0.9.5.4`); die Release-Notes nennen die Korrektur als **Sicherheitsupdate**, Details erst, wenn die Betreiber Zeit zum Aktualisieren hatten.
- Wer die Lücke gemeldet hat, wird auf Wunsch in den Release-Notes genannt.

## Unterstützte Versionen

Sicherheitskorrekturen gibt es für das jeweils **neueste Release**. Betreiber sollten mindestens monatlich aktualisieren (Wiki → Installation → „Updates einspielen“).

## Wie openwehr abgesichert wird

- **Abhängigkeiten:** Dependabot schlägt jede Woche Updates für PHP-, JavaScript- und Docker-Abhängigkeiten sowie die GitHub-Actions vor; Sicherheitsupdates sofort.
- **Bei jeder Änderung** (Push und Pull Request): rund 900 automatische Tests (auch Rechte je Rolle), `composer audit` und `npm audit` gegen bekannte Schwachstellen, statische Analyse (Larastan), Suche nach versehentlich eingecheckten Geheimnissen (Gitleaks) und ein Scan des Docker-Images (Trivy) vor der Veröffentlichung.
- **Jede Woche** zusätzlich, auch ohne Änderung: Abhängigkeiten und das veröffentlichte Image gegen neue Schwachstellenmeldungen.
- **Pull Requests** bekommen einen KI-Sicherheitsreview (Claude Code Security Review); vor Releases werden Anmeldung, Rechte, Uploads, signierte Links und Schnittstellen gezielt geprüft.
- **In der App:** Anmeldung ohne Passwort über einmalige, kurzlebige Links (nur gehasht gespeichert), Rechte in den Services statt nur in der Oberfläche, Gesundheitsdaten verschlüsselt, unveränderbare Einträge per Datenbank-Trigger, Security-Header (CSP, HSTS, X-Frame-Options), sichere Auslieferung hochgeladener Dateien, Schutz vor Formeln in CSV-Exporten, keine Abrufe interner Adressen über eingegebene URLs, Aktivitätsprotokoll.

Ein unabhängiger Penetrationstest steht noch aus.
