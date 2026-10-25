#!/usr/bin/env python3
"""Builds the GitHub wiki pages from README.md.

Usage: python3 scripts/build-wiki.py <output-dir> <version>
Then copy the pages into a clone of https://github.com/roberteinsle/openwehr.wiki.git and push.
The README stays the source; the wiki is a snapshot per version.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = 'https://github.com/roberteinsle/openwehr'
src = open(os.path.join(ROOT, 'README.md'), encoding='utf-8').read().split('\n')
out, version = sys.argv[1], sys.argv[2]


def slug(h):
    h = re.sub(r'[^\w\- ]', '', h.strip().lower(), flags=re.UNICODE)
    return h.replace(' ', '-')


def page_name(title):
    t = re.sub(r'\(.*?\)', '', title.replace('&', 'und').replace('`', '')).strip()
    t = re.sub(r'[^\w\- äöüÄÖÜß]', '', t)
    return re.sub(r'\s+', '-', t.strip())


secs, cur = [], None
for line in src:
    if line.startswith('## '):
        cur = {'title': line[3:].strip(), 'lines': []}
        secs.append(cur)
    elif cur is not None:
        cur['lines'].append(line)
intro = src[2:src.index(next(l for l in src if l.startswith('## ')))]
S = {s['title']: s for s in secs}
pages, anchor = {}, {}


def add(page, title, lines, readme_titles):
    pages[page] = (title, lines)
    for t in readme_titles:
        anchor[slug(t)] = page


def sub(lines, name):
    o, on = [], False
    for l in lines:
        if l.startswith('### '):
            on = l[4:].strip().startswith(name)
        if on:
            o.append(l)
    return o


# "## Funktionen" belongs to the Fahrzeugbuchung, apart from the core parts
fun = S['Funktionen']['lines']
booking = ['Rollen', 'Kalender', 'Buchen', 'Nachrichten zur Buchung', 'Konflikte und Entscheidungen', 'Wachen-Monitor']
fb = S['Fahrzeugbuchung']['lines'] + ['']
for n in booking:
    fb += sub(fun, n)
add('Fahrzeugbuchung', 'Fahrzeugbuchung', fb, ['Fahrzeugbuchung', 'Funktionen'])
add('Anmeldung-und-Profil', 'Anmeldung und Profil', sub(fun, 'Anmeldung') + sub(fun, 'Profil') + sub(fun, 'Feedback'), [])
add('Admin-Bereich', 'Admin-Bereich', sub(fun, 'Admin-Bereich') + sub(fun, 'E-Mails'), [])
for h in ['Anmeldung', 'Profil', 'Feedback']:
    anchor[slug(h)] = 'Anmeldung-und-Profil'
for h in ['Admin-Bereich (`/admin`)', 'E-Mails']:
    anchor[slug(h)] = 'Admin-Bereich'
for h in booking:
    anchor[slug(h)] = 'Fahrzeugbuchung'

about = ['Tech-Stack', 'Lizenz', 'Ideen und Weiterentwicklung']
modules = []
for s in secs:
    t = s['title']
    if t in ('Fahrzeugbuchung', 'Funktionen', 'Module im Überblick', *about):
        continue
    p = page_name(t)
    add(p, t, s['lines'], [t])
    for l in s['lines']:
        if l.startswith('### '):
            anchor[slug(l[4:])] = p + '#' + slug(l[4:])
    if t not in ('Startseite und Navigation', 'Installation', 'Konfiguration', 'Sicherheit'):
        modules.append(p)
extra = []
for t in about:
    if t in S:
        extra += ['## ' + t] + S[t]['lines']
add('Über-openwehr', 'Über openwehr', extra, about)


def fix(text):
    def repl(m):
        label, target = m.group(1), m.group(2)
        if target.startswith('#'):
            return f'[{label}]({anchor.get(target[1:], target)})'
        if re.match(r'^(https?:|mailto:)', target):
            return m.group(0)
        return f'[{label}]({REPO}/blob/main/{target})'
    return re.sub(r'\[([^\]]+)\]\(([^)\s]+)\)', repl, text)


os.makedirs(out, exist_ok=True)
for p, (title, lines) in pages.items():
    body = re.sub(r'^### ', '## ', '\n'.join(lines).strip('\n'), flags=re.M)
    open(f'{out}/{p}.md', 'w', encoding='utf-8').write(fix(body) + '\n')

overview = fix('\n'.join(S['Module im Überblick']['lines']).strip('\n'))
intro_text = fix(' '.join(l for l in intro if l.strip()))
open(f'{out}/Home.md', 'w', encoding='utf-8').write(f"""**openwehr** – {intro_text}

Dieses Wiki beschreibt, was openwehr kann und wie es eingerichtet wird – für Wehrführung, Admins, Rollenträger und Mitglieder. Stand: **{version}**. Die Grundlage ist die [README]({REPO}#readme) im Repository; bei Abweichungen gilt sie.

## Einstieg
- [[Installation]] – Server, Coolify, Datenbank, erste Schritte, Updates und Sicherungen
- [[Konfiguration]] – Umgebungsvariablen
- [[Startseite und Navigation|Startseite-und-Navigation]] – Startseite, Glocke, Modulleiste, Personalakte
- [[Anmeldung und Profil|Anmeldung-und-Profil]] – Login ohne Passwort, Profil, Feedback
- [[Admin-Bereich]] – Kameraden, Abteilungen, Funktionen, Module, E-Mail-Texte, Logs
- [[Sicherheit]] – Updates, Prüfungen, Lücken melden

## Module im Überblick
{overview}

Weitere Infos: [[Über openwehr|Über-openwehr]] (Technik, Lizenz, Ideen) · [Releases]({REPO}/releases) · [www.openwehr.de](https://www.openwehr.de)
""")
side = ['**[[Startseite|Home]]**', '', '**Einstieg**', '- [[Installation]]', '- [[Konfiguration]]',
        '- [[Startseite und Navigation|Startseite-und-Navigation]]', '- [[Anmeldung und Profil|Anmeldung-und-Profil]]',
        '- [[Admin-Bereich]]', '- [[Sicherheit]]', '', '**Module**', '- [[Fahrzeugbuchung]]'] \
    + [f'- [[{pages[p][0]}|{p}]]' for p in modules] + ['', '- [[Über openwehr|Über-openwehr]]']
open(f'{out}/_Sidebar.md', 'w', encoding='utf-8').write('\n'.join(side) + '\n')
open(f'{out}/_Footer.md', 'w', encoding='utf-8').write(
    f'openwehr {version} · freie Software unter der [AGPL-3.0]({REPO}/blob/main/LICENSE) · '
    f'[www.openwehr.de](https://www.openwehr.de) · Fehler oder Wünsche: [Issues]({REPO}/issues)\n')
unresolved = set(re.findall(r'\]\((#[^)]+)\)', ''.join(open(f'{out}/{f}', encoding='utf-8').read() for f in os.listdir(out))))
print(f'{len(pages) + 1} pages written to {out}' + (f'; unresolved anchors: {unresolved}' if unresolved else ''))
