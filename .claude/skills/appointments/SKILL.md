---
name: appointments
description: Development plan and domain knowledge for com_appointments, the self-hosted Joomla scheduling component that replaces Calendly. Use when implementing, reviewing or releasing com_appointments, its booking flow, availability engine, calendar feeds, e-mail delivery or video-conferencing links.
triggers:
  - appointments
  - com_appointments
  - booking
  - scheduling
  - calendly
  - availability
  - time slot
  - ICS
  - iCalendar
  - calendar feed
  - video conferencing
  - jitsi
  - meet
references:
  - references/scope.md
  - references/architecture.md
  - references/data-model.md
  - references/calendar-and-ics.md
  - references/conferencing.md
  - references/privacy-and-deletion.md
  - references/implementation-plan.md
  - references/testing.md
---

# com_appointments — Self-hosted scheduling for Joomla

## What this is

`com_appointments` is a Joomla component that lets a site operator publish bookable time slots on
their own website. A visitor picks a date and a time, fills in a short form and receives a
confirmation e-mail with an attached calendar entry. The site owner receives the same entry and can
subscribe to a per-event-type calendar feed from their desktop mail client.

It replaces a hosted scheduling service. Everything runs on the operator's own Joomla server:
availability rules, slot calculation, booking storage, e-mail delivery, iCalendar generation and the
video-conference link. **No third-party scheduling service, no external API, no account at any
provider is required or contacted.** This is the defining constraint of the project, not a
preference — see `references/architecture.md`, section "Self-hosted by design".

## Non-negotiable constraints

1. **No external service calls for the core booking flow.** No cloud calendar API, no hosted
   scheduling backend, no analytics, no remote font or script. The component must work on a server
   with all outbound traffic blocked, except for the optional conferencing adapters that are
   explicitly opt-in.
2. **The component owns the calendar.** It does not read a user's existing mailbox or calendar to
   detect conflicts. Conflicts are prevented by the component's own availability rules, its own
   bookings and its own blocked periods. Anything else is a later, opt-in extension.
3. **Every outgoing e-mail carries the iCalendar entry**, as an attachment, for every state change:
   confirmation, reschedule and cancellation. See `references/calendar-and-ics.md`.
4. **An invitee can get their data deleted without an account**, from a link in the e-mail they
   already hold, and through Joomla's own data subject request workflow. See
   `references/privacy-and-deletion.md`. This is a shipped feature, not a manual database task for
   the operator.
5. **Joomla 6 only** for this component. See `references/architecture.md`, section "Platform".
6. **GPL-3.0-or-later**, published in this public repository, usable by any Joomla operator. No
   hardcoded reference to the first deployment's domain, branding, texts or server names. Anything
   site-specific is a component parameter or a per-event-type field.

## Where to start

| Question | File |
|---|---|
| What exactly is in scope, and what is deliberately not? | `references/scope.md` |
| How is the component structured, which Joomla APIs are used? | `references/architecture.md` |
| Which tables, which columns, which indexes? | `references/data-model.md` |
| How are slots computed, how is the ICS built, how is the feed secured? | `references/calendar-and-ics.md` |
| How does a booking get a conference link? | `references/conferencing.md` |
| How does a person get their data deleted? | `references/privacy-and-deletion.md` |
| In which order is this built, and what is the acceptance criterion per stage? | `references/implementation-plan.md` |
| How is it tested, which CI workflows are added? | `references/testing.md` |

## Repository conventions that apply

Read `.claude/skills/joomla-extensions/SKILL.md` and its references first. They are binding and are
not repeated here. The points most easily missed on a new component:

- `LICENSE.txt` must be declared in the manifest `<files>` list of **both** the administrator and
  the site part, and the file must exist at both source paths. `php shared/tests/requirements-check.php com_appointments`
  checks this.
- Never edit `VERSION`, the manifest `<version>` or `updates/update.xml` by hand. The release
  workflow owns them.
- Conventional Commits with the scope `appointments`.
- Language files for `de-DE`, `en-GB` and `fr-FR`. German is Swiss High German, so `ss` and never `ß`.
- README in English.

`com_dmarcreports` is the most recent component in this repository and the structural reference for
everything that is not booking-specific: directory layout, `build.sh`, `build.env`, `script.php`,
`services/provider.php`, the three CI workflows, the test harness and the skill layout. Copy its
shape, do not reinvent it.
