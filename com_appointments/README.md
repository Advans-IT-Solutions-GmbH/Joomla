# Appointments (com_appointments)

Self-hosted appointment scheduling for Joomla. The site operator publishes bookable time slots, a
visitor picks a date and a time, and the confirmation e-mail carries the calendar entry as an
attachment so it lands in a locally installed mail client.

Everything runs on the operator's own server: availability rules, slot calculation, booking storage,
e-mail delivery, iCalendar generation and the conference link. **No third-party scheduling service
is required or contacted**, and the component works on a server whose outbound internet access is
blocked.

> **Status: in development.** The component is being built in stages. This file grows with the
> implemented feature set and will document every shipped setting before the first release. The
> sections below say what is implemented today and name what is still to come, so nothing here
> should be read as a feature you can already use.

## Requirements

- Joomla 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or later
- MySQL or MariaDB. The installer refuses any other database server, because the booking table uses
  a generated column to guarantee that one time slot can be held by exactly one booking.

## Installation

1. Download the package `com_appointments_<version>.zip` from the repository releases.
2. Install it in Joomla through **System → Install → Extensions**.
3. Open **Components → Appointments**.

The component registers its own update site during installation, so later versions are offered by
Joomla's extension update screen.

## What is implemented so far

- The component skeleton with its administrator and site part, the full database schema, Joomla
  access control on the component, and the three shipped languages (`de-DE`, `en-GB`, `fr-FR`).
- An administrator dashboard that shows the number of event types and bookings and an empty state
  while nothing is configured yet.
- A public page that lists the bookable event types a visitor is allowed to see.

## Signing keys

Key derivation is implemented; the features that will use it are not. `KeyProvider` derives one key
per purpose from the Joomla site secret of the installation, and the two purposes it defines are the
signed links in the component's e-mails and the hashing of IP addresses in the rate-limit counters.
Both of those arrive in later stages.

The property that matters is already in place and covered by the unit suite: no key is stored in the
database, printed into a page or written to a log, and no key changes when the component is updated,
so a link that is already in someone's mailbox will keep working.

## Uninstalling

Uninstalling removes the component files and drops every table it created, including all stored
bookings. Export what you still need first.

## License

GNU General Public License version 3 or later; see `LICENSE.txt`.
