# Standards für alle Apps

Gilt für jedes Repository von roberteinsle. Die `CLAUDE.md` eines Projekts bindet diese Datei mit `@docs/STANDARDS.md` ein und enthält nur noch das Projektspezifische. Weicht ein Projekt bewusst ab, steht das dort ausdrücklich mit Begründung; sonst gilt diese Datei.

Quelle: `roberteinsle/.github/STANDARDS.md`. Kopien in den Projekten (`docs/STANDARDS.md`) nicht von Hand ändern, sondern hier ändern und neu kopieren.

## Stack und Versionen

- Immer die neueste stabile Version: **PHP 8.5, Laravel 13, Filament 5, PostgreSQL 18** (Stand November 2026).
- Neue PHP-Minor-Versionen nach einem Testlauf innerhalb von 4 Wochen übernehmen. Die Vorgängerversion nur übergangsweise, wenn eine Abhängigkeit die neue noch nicht unterstützt; ältere nicht mehr verwenden.
- Images auf die PHP-Minor-Version pinnen (`8.5-…`), nicht auf den Patch – jeder Build zieht so den neuesten Patch.
- Die PHP-Version steht an genau drei Stellen und wird im selben Commit geändert: `Dockerfile` (`ARG PHP_VERSION`), `composer.json` (`"php": "^8.5"`), `.devcontainer/devcontainer.json`.
- Für Library-Dokumentation (Laravel, Filament, Livewire, Pest …) den Context7-MCP-Server nutzen, wenn verfügbar, nicht aus dem Gedächtnis arbeiten. Vor der Nutzung einer API die installierte Version prüfen (`composer show <paket>`, `package.json`).

## Sprache

- Code, Bezeichner, Kommentare und Commit-Messages: Englisch.
- Oberfläche, E-Mails, Validierungsmeldungen und `handbuch/`: Deutsch, UI-Texte trotzdem über `__()`.
- URLs und Filament-Slugs deutsch (`/neu`, `/{record}/bearbeiten`).
- Projekte mit anderer Zielgruppe (z. B. englische Open-Source-Apps) legen das in ihrer `CLAUDE.md` fest.

## Tests und Qualität

- **Tests laufen immer gegen PostgreSQL, nie gegen SQLite** – lokal, im Codespace und in der CI. Testdatenbank `app_test` (wie in der Vorlage `app-template`), `RefreshDatabase`. Migrationen müssen auf PostgreSQL gültig sein.
- Neue Projekte nutzen Pest.
- Vor jedem Commit: `vendor/bin/pint`, `vendor/bin/phpstan analyse` (Larastan), Tests.
- Jede neue Funktion bekommt Tests, insbesondere für Rechte je Rolle und für die Anmeldung.
- Pflicht-Tests für die Anmeldung: SQL-Injection-Payloads im E-Mail-Feld und im Token (`' OR '1'='1`, `' OR 1=1 --`, `admin'--`, `'; DROP TABLE users; --`, `" OR ""="`, `1' UNION SELECT NULL--`, auch URL-kodiert) → kein Login, kein 500, keine DB-Details in der Antwort; abgelaufenes, benutztes, manipuliertes und falsch formatiertes Token; gleiche Antwort für bekannte und unbekannte Adressen; Rate-Limit greift.
- Testdaten, Seeder und Beispiele nur mit erfundenen Personen (Erika Mustermann, `example.com`).

## CI (GitHub Actions)

Bei jedem Push und Pull Request:

- Tests gegen einen PostgreSQL-Service
- Pint (`--test`) und Larastan
- `composer audit` und `npm audit --omit=dev`
- Gitleaks gegen eingecheckte Geheimnisse
- Bundle-Prüfung: Build schlägt fehl, wenn das Frontend-Bundle etwas enthält, das wie ein Schlüssel aussieht
- Trivy-Scan des Docker-Images vor dem Push

Zusätzlich wöchentlich, auch ohne Änderung: Rebuild des Images (neueste PHP-Patches) und Scan gegen neue Schwachstellen. Dependabot ist in jedem Repo aktiv.

## Deployment

Coolify baut nie selbst.

1. Entwicklung im Codespace (`.devcontainer/` mit PHP und PostgreSQL).
2. `.github/workflows/build.yml`: Tests → Docker-Build mit `pull: true` → Push nach `ghcr.io/roberteinsle/<repo>` (Tags `latest`, `sha-…`, bei Releases semver) → `php -v` im Image ausgeben.
3. Coolify startet `deploy/docker-compose.coolify.yml` und holt nur Images.
4. **PostgreSQL ist eine eigene Coolify-Ressource**, nie ein Service in `deploy/docker-compose.coolify.yml` (für die lokale Entwicklung darf `docker-compose.yml` eine Datenbank starten). Die App erreicht sie über das externe Netzwerk `coolify` und `DB_HOST`.
5. Alles Umgebungsspezifische kommt aus Coolify-Env-Variablen. `.env*` ist ignoriert, nur `.env.example` (ohne echte Werte) ist eingecheckt. `env()` nur in `config/`, sonst `config()` – der Container cacht die Konfiguration.
6. Migrationen laufen beim Containerstart.
7. Rollback: in Coolify `APP_TAG` auf einen `sha-…`-Tag setzen und neu deployen. Nie auf ein Image mit ungepatchtem PHP zurückrollen.
8. `main` ist immer deploybar; größere Arbeiten auf Feature-Branches. Kein `develop`, kein `staging`.
9. Auto-Deploy ist der Normalfall: Repo-Variable `AUTO_DEPLOY=true`, Secrets `COOLIFY_WEBHOOK` und `COOLIFY_TOKEN`. Ausnahme nur, wenn GitHub Coolify nicht erreicht (z. B. Coolify nur im Tailnet) – dann steht das in der `CLAUDE.md`.
10. Ein Repo = eine App = ein Image. Queue-Worker und Scheduler laufen als eigene Container aus demselben Image mit anderem Kommando, nicht per Supervisor im App-Container. Hilfsdienste (Gotenberg, Redis) sind fremde Images im Compose.
11. Daten (Uploads, generierte Dateien) liegen in benannten Volumes, nie im Container.
12. Releases als Git-Tag `vX.Y.Z` nach SemVer: Major bei Brüchen im Datenmodell, Minor bei neuen Funktionen und PHP-Minor-Wechseln, Patch bei Korrekturen.

Ausführliche Abläufe (Einrichtung, PHP-Updates, Rollback, Aufräumen in GHCR): `roberteinsle/.github/DEPLOYMENT.md`.

Bewusst nicht Teil des Standards: Staging, Preview-Deployments, Zero-Downtime, Kubernetes, Terraform – erst, wenn eine konkrete App es nachweislich braucht.

## Anmeldung

Reihenfolge der Vorlieben: **Passkeys** (`laravel/passkeys`) → **Magic Link** → Passwort nur mit verpflichtender TOTP-2FA. Kein reines Benutzername-Passwort-Login. Nach der ersten Anmeldung per Magic Link bietet die App an, einen Passkey einzurichten.

Magic Links:

- Token mit kryptografisch sicherem Zufall (mindestens 32 Byte, z. B. `random_bytes(32)` → 64 Hex-Zeichen).
- In der DB nur der SHA-256-Hash, nie der Klartext; Lookup über den Hash. Wo nicht per DB-Lookup verglichen wird: `hash_equals`.
- Gültigkeit höchstens 15 Minuten, einmalig verwendbar. Einlösen atomar (`UPDATE … WHERE used_at IS NULL`), damit ein Doppelklick nicht zweimal anmeldet.
- Eine neue Anforderung macht ältere offene Links desselben Kontos ungültig; eine erfolgreiche Anmeldung löscht alle übrigen.
- Das Token-Format wird geprüft (z. B. exakt 64 Hex-Zeichen, als Routen-Constraint), **bevor** eine Abfrage läuft; alles andere sofort 404.
- Der GET aus der Mail zeigt nur einen Bestätigungsknopf, erst der POST meldet an (Mail-Scanner rufen Links vorab auf).
- Keine User-Enumeration: Die Antwort ist immer gleich („Falls die Adresse existiert, wurde ein Link gesendet“), auch zeitlich (Suche und Versand nach der Antwort, z. B. `defer()`).
- E-Mail serverseitig validieren (`required|email|max:255`), trimmen, kleinschreiben; Suche nur mit gebundenen Werten.
- Rate-Limits auf das Anfordern (je Adresse und je IP) und auf das Einlösen.
- Nach der Anmeldung die Session-ID regenerieren (`$request->session()->regenerate()`).
- Redirect-Parameter (`?next=`, `?redirect=`) nur auf interne Pfade, sonst Startseite (Open Redirect).
- Token nie in Logs, Aktivitätsprotokoll oder Fehlermeldungen; `Referrer-Policy: no-referrer` bzw. `same-origin`, damit es nicht über den Referer abfließt.
- Link-Host nur aus `APP_URL` oder einer festen Allowlist, nie ungeprüft aus dem Request (Host-Header-Poisoning).

Erster Admin über die Env-Variable `ADMIN_EMAIL`, nie fest im Code.

Für eine vollständige Prüfung eines bestehenden Login-Flows: Slash-Command `/login-audit` (`.claude/commands/login-audit.md`).

### Admin-Bereich hinter Cloudflare Access

Ist eine App öffentlich erreichbar, liegt ihr Admin-Bereich zusätzlich hinter **Cloudflare Zero Trust (Access)**:

- Der Admin-Bereich hat einen eigenen, festen Pfad-Präfix (z. B. `/admin`), damit eine Access-Anwendung genau diesen Pfad schützen kann. Keine Admin-Funktionen außerhalb dieses Präfixes.
- Access ist eine zusätzliche Schicht: Die App prüft Anmeldung und Rechte trotzdem vollständig selbst und funktioniert auch ohne Access sicher.
- Der Server nimmt Web-Verkehr nur über Cloudflare an (Cloudflare Tunnel oder Firewall nur für Cloudflare-IPs), sonst lässt sich Access über die Server-IP umgehen.
- Die Access-Policy umfasst alle, die den Admin-Bereich brauchen (nicht nur den Maintainer), wenn die App weitere Admins oder Modulverantwortliche hat.
- Apps, die nur im Tailnet erreichbar sind, brauchen das nicht.
- Open-Source-Apps, die andere selbst betreiben, setzen Access nicht voraus; das Handbuch empfiehlt es den Betreibern.

## Sicherheitsregeln

Gelten für jede neue Funktion.

- **SQL:** Nur Eloquent oder Query Builder, Werte immer gebunden. In `whereRaw`, `selectRaw`, `orderByRaw`, `DB::raw` nie Eingaben einsetzen; wenn Raw unvermeidbar ist: Platzhalter. Spalten- und Tabellennamen aus Anfragen nur gegen eine feste Liste.
- **Rate-Limits:** Jede von außen erreichbare Route hat eins, auch Login, Magic Link und öffentliche Formulare. Teure Endpunkte (KI, Import, Export, Uploads, externe APIs) ein eigenes, strengeres. Kostenlimits gehören in die Fachlogik, nicht nur an die Route, sonst umgehen Livewire-Aktionen sie. Hinter Cloudflare die echte Besucher-IP verwenden (vertrauenswürdige Proxys konfigurieren), nicht die des Edge-Servers.
- **Externe bezahlte APIs:** Tages- oder Monatslimit und Limit je Benutzer. Arbeiten ohne Wartende laufen als Queue-Job mit `RateLimited`.
- **Eingaben:** Jedes Feld serverseitig geprüft, auch in Filament: Pflicht oder optional, Datentyp, maximale Länge. Uploads zusätzlich `mimes` und Größe. Browser-Prüfung ist nur Komfort.
- **Nur geprüfte Daten:** `$request->validate()` statt `$request->all()`. Models mit `$fillable`, nie `$guarded = ['id']`; interne Felder (Sperren, Status, Rollen) über `forceFill`.
- **Rechte:** Serverseitig in Policies, Gates oder Services prüfen, nie nur durch Ausblenden in der Oberfläche. Jede neue Filament-Resource und -Seite hat eine Rechteprüfung.
- **Ausgabe:** In Blade `{{ }}`. `{!! !!}` nur mit bereits escapten, gesäuberten oder fest vorgegebenen Werten. Markdown aus Eingaben oder KI-Antworten mit `html_input => escape` rendern.
- **Schlüssel:** Nur als Coolify-Env, gelesen über `config()`. Nie im JavaScript, HTML oder Repo; kein Geheimnis mit Präfix `VITE_`. Das Frontend ruft das eigene Backend auf, erst dieses die externe API. Ist ein Schlüssel öffentlich geworden: sofort beim Anbieter sperren, neu erzeugen, in Coolify ersetzen, neu deployen.
- **Gespeicherte Geheimnisse und sensible Daten** (SMTP-Passwörter, API-Keys in der DB, 2FA-Secrets, Gesundheitsdaten) mit dem `encrypted`-Cast. `APP_KEY` in Produktion nie ändern.
- **Produktion:** `APP_DEBUG=false`, auch bei internen oder nur per VPN erreichbaren Apps. Keine SQL-Fehler, Stacktraces oder Query-Details an den Client; Fehler über Log oder eine Admin-Seite ansehen.
- **Datenbank-Benutzer der App:** nur die nötigen Rechte auf die eigene Datenbank, kein Superuser.
- **Security-Header:** CSP, HSTS, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.
- **Uploads:** Auf einer privaten Disk speichern und über einen Controller mit Rechteprüfung ausliefern. Inline nur PDFs und Rasterbilder, alles andere als Download.
- **Externe URLs:** Werden URLs aus Eingaben abgerufen, keine internen oder privaten Adressen zulassen (SSRF); nur `https`, Timeout und Größenlimit.
- **CSV-Exporte:** Zellen, die mit `=`, `+`, `-` oder `@` beginnen, entschärfen (Formel-Injection).
- **Datenschutz im Frontend:** Keine externen Ressourcen (Fonts, CDNs, Embeds) ohne Einwilligung, alles lokal ausliefern.
- **Protokoll:** Änderungen an Fachdaten in ein Aktivitätsprotokoll schreiben; Geheimnisse und Dateiinhalte darin maskieren.
- **Mails:** Ein fehlgeschlagener Mailversand wird gemeldet, macht aber die eigentliche Aktion nicht rückgängig.

## Keine persönlichen Daten

Keine persönlichen Daten des Maintainers ins Repository – weder in Code, Doku, `handbuch/`, Wiki, Commits noch Issues. Dazu zählen Namen, E-Mail-Adressen, Konten, private Domains, Server und Orte. Das gilt auch für private Repos, weil sie später öffentlich werden können. Beispiele mit `example.com` und Musternamen; echte Werte nur aus Env-Variablen. Ausnahmen (z. B. gesetzlich nötiges Impressum) stehen in der `CLAUDE.md` des Projekts.

## Architektur

- Fachlogik in Services oder Actions, nicht in Controllern, Livewire-Komponenten oder Models. Zustandsänderungen nur über diese Services.
- Module und neue Funktionen werden ausschließlich über Registries angebunden, nie über direkte Zugriffe auf Interna anderer Module. Vor jeder neuen Funktion prüfen, wie sie mit den bestehenden zusammenspielt.
- Zeit: in UTC als `timestamptz` speichern, explizit in `Europe/Berlin` anzeigen.
- Navigation nach Jakob's Law (Nutzer erwarten, was sie aus anderen Apps kennen):
  - Web: Logo oben links führt zur Startseite, Hauptnavigation oben oder links, Konto oben rechts.
  - Handy: untere Tab-Leiste mit 3–5 Hauptbereichen; Hamburger-Menü nur für Nebensachen.
  - Zurück-Button und Wischgeste verhalten sich wie erwartet; kein Scrolljacking.
  - Bekannte Symbole (Lupe, Zahnrad, Haus); eigene Icons nur mit Textlabel.
  - Admin-Oberflächen im Filament-Standardlayout.
  - Hauptaktion einer Seite einmal neben dem Titel.
  - Abnahme: Jemand aus der Zielgruppe, der die App nicht kennt, erledigt die Hauptaufgabe ohne Erklärung.
- Mobile first.

## Dokumentation

Bei jeder Änderung nachziehen, soweit betroffen, im selben Commit:

- `handbuch/<Seite>.md`: alle Details für Benutzer und Betreiber; Quelle des GitHub-Wikis.
- `README.md`: kurze Übersicht; nur bei neuem Modul oder geändertem Schnellstart ändern.
- `CLAUDE.md`: bei Änderungen an Architektur, Regeln oder Abläufen.
- Wiki: mit `scripts/build-wiki.py` aus `handbuch/` erzeugen, nie von Hand bearbeiten.

## Ablauf

Committen und pushen, wenn eine Änderung fertig und getestet ist. Releases nur auf ausdrücklichen Wunsch.
