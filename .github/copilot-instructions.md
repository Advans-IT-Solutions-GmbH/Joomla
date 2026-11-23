# Copilot Instructions

Nutze in diesem Repository die Vorgaben aus `/AGENTS.md` (Repo-Root) als maßgebliche Quelle (Git-Workflow, Commit-Konventionen, Branch-Naming).

## Harte Regeln (Details in `AGENTS.md`)

- Jeder Commit des PR ist signiert und zeigt «Verified». In Sitzungen des Maintainers: SSH-Signatur, Identität `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`; sonst die eigene Identität und der eigene Schlüssel der Person, in deren Sitzung du arbeitest (Details in `AGENTS.md`). Kann nicht signiert werden: anhalten und melden, nie unsigniert committen.
- Kein `Co-authored-by`-Trailer und keine Agent-Signatur («Copilot», «copilot-swe-agent», «Claude», «Generated with», «🤖») in einer Commit-Nachricht. Die CI-Prüfung «Commit hygiene» blockiert den Merge sonst.
- Nie auf `main` pushen, immer Feature-Branch + PR (`fix/…`, `feat/…`, `docs/…`, `chore/…`); nie selbst mergen.
- Conventional Commits (`fix:`/`feat:`) bestimmen die Release-Version.
- Fertig (DoD): CI grün, Copilot-Überblick «🟢 Approval recommended» mit allen Threads erledigt, vor jedem Release der Test auf dev.

Repo-spezifische Skills liegen unter `.claude/skills/` und gelten auch für Copilot. Bei passendem Thema jeweils das `SKILL.md` im passenden Skill-Ordner lesen:
- `.claude/skills/joomla-extensions/` — Entwicklung, Tests, Releases, CI/CD
- `.claude/skills/privacy-plugin/` — plg_privacy_j2commerce Domain-Wissen
- `.claude/skills/dmarc-reports/` — com_dmarcreports, mod_dmarcreports, plg_task_dmarcreports, plg_system_dmarcmtasts und pkg_dmarcreports Domain-Wissen
- `.claude/skills/appointments/` — com_appointments Domain-Wissen und verbindlicher Entwicklungsplan

Commit-Identität:
- Entwickler committen unter ihrer **eigenen** Identität (eigener Account, eigene GitHub-No-Reply-Adresse; signiert und verifiziert).
- Der Maintainer und KI-Agenten in seinen Sitzungen committen als `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`. Details in `/AGENTS.md`.

Signing-Regeln:
- Das Organization-Ruleset verlangt verifizierte Signaturen auf jedem Commit eines PR, auch im Feature-Branch; der signierte Squash-Merge ersetzt das nicht. Unsignierte Commits sind nicht erlaubt. Details in `/AGENTS.md`.

Label: Die Extensions tragen swiss made software. Logo unverändert (farbig, schwarzweiss, auf GitHub auch mit weissem Rand), Link auf die Landingpage des Labels (auf GitHub im selben Fenster, weil GitHub `target` entfernt), Herkunftsangaben nur gemäss Label. Kein ausländischer Dienst fest im Laufzeitcode und keine ausländische Vorgabe, externe Dienste bleiben einstellbar; eine neue externe Adresse im Laufzeitcode und eine neue externe Vorgabe brauchen einen Entscheid des Maintainers. Details in `/AGENTS.md`, Abschnitt „Label swiss made software“.

Lizenz: Alle Extensions stehen unter GPL-3.0-or-later; Kopfvorlage und Regeln für Fremdcode in `/AGENTS.md` (Abschnitt „Lizenz“).

PR-only-Workflow: Änderungen nur per PR, Squash-Merge, Agenten mergen nie selbst. Review-Befunde sofort beheben, nicht auf die CI warten.

Öffentliches Repository: keine Kundendaten, Hostnamen, IP-Adressen oder internen Details — auch nicht in Commit-Nachrichten, PR-Texten und Kommentaren. Weitere festgehaltene Entscheidungen: `/AGENTS.md`, Abschnitt „Repo-spezifisch (Joomla)“.
