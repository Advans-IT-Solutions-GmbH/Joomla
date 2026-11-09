# Privacy and deletion

An invitee is almost never a registered user of the site. They give a name, an e-mail address, a
phone number and whatever the event type asks for, and they never log in. The component therefore
has to offer a deletion path that works **without an account**, and it has to offer it where the
person actually is: in the e-mail they already hold.

Three independent paths are required. All three are part of version 1.

## Path 1 — self service from every e-mail

Every e-mail the component sends to an invitee carries two signed links in its footer, in the
recipient's language:

- **Reschedule / cancel** — the existing booking link.
- **Delete my data** — a second link with its own action in the HMAC, so a cancel link can never be
  replayed as a deletion.

The deletion link opens a page on the site that states, in plain language, exactly what will be
removed and what will remain, and asks for one confirmation. No login, no further e-mail round trip,
no contact form.

### What the confirmation page says

- Which booking it concerns (event type, date, time) and which e-mail address.
- That name, e-mail address, phone number and all answers to the booking form will be erased
  immediately and cannot be restored.
- That a record of the appointment slot itself is kept in anonymised form, without any personal
  data, so the operator's own statistics and accounting stay consistent. If the operator has turned
  that off, the page says the booking is deleted entirely instead.
- That a future appointment will be cancelled by the deletion, and that the host is notified of the
  cancellation but not of the reason.

### What deletion does

| Field | After deletion |
|---|---|
| `invitee_name`, `invitee_first_name`, `invitee_last_name`, `invitee_email`, `invitee_phone`, `invitee_timezone` | erased |
| all custom field answers | erased |
| guest e-mail addresses | erased |
| `cancel_reason`, any free text | erased |
| conference room identifier and link | erased, and the room is torn down if the adapter supports it |
| anti-bot timing data | never stored in the first place, so there is nothing to erase |
| `start_utc`, `end_utc`, `eventtype_id`, `state`, `created_utc` | kept |
| `consent_utc`, `consent_version` | kept: the proof that the processing had a consent, which the operator may have to show after the erasure (accountability). Without the erased contact data they identify nobody; the version is a hash of mode, language and label text, never of personal data. In delete mode they go with the row |
| `anonymised_utc` | set |

A future booking is set to cancelled first, so the slot is released and the host is notified, and
then anonymised. The host notification contains the slot, never the reason and never the former
invitee data.

If the operator has set "delete instead of anonymise", the row is deleted outright and the slot
is released the same way.

### Properties the implementation must have

- The link action is part of the signature. `SignedLink` takes `(booking id, action, modified)`;
  `action` is one of `reschedule`, `cancel`, `erase`.
- The link stops working once used, because `modified` changes.
- A deletion is logged in a separate audit table that holds **no data of an invitee**: booking id,
  timestamp, path used (`self_service`, `privacy_request`, `retention`, `admin`), and for the
  `admin` path the acting administrator's Joomla user id. The operator needs this to prove the
  request was honoured, and it must not become a second copy of the data that was erased. See
  "What the audit table is, and what it is not" below.
- Deleting is idempotent. A second click says the data is already gone, and does not error.
- Booking erasure and its audit run in one transaction. Calendar/provider teardown runs
  after commit; its failure must never be reported as complete erasure.

### Additional guests remove their own address

A guest is not the person who booked, so the invitee's links are never in a guest's mail. Every
guest mail (the `guest_*` templates, neutral salutation) instead carries one signed link of the
guest's own, `task=guest.confirm&id=…&signature=…`:

- Signed with its own `KeyProvider` purpose (`guest-link`) over booking id, the action
  `remove_guest`, the guest address in Unicode lower case and `modified_utc`. The address is not in
  the URL; the server finds the guest whose signature it is among the booking's guests.
- GET shows the appointment and the guest's own address, nothing of the invitee or the other
  guests, and a POST form with the CSRF token (task `guest.remove`). Both are rate limited
  (bucket `guest`, 60 per 15 minutes).
- POST removes exactly that address from `guest_emails`, under a lock of the booking row only (no host lock: it changes no availability, and a mail delivery holding the row with a shared lock makes it wait without stalling the host's other bookings), and writes
  an audit row `remove_guest` / `self_service` without the address. The booking, the invitee's data
  and the other guests stay.
- `modified_utc` is deliberately **not** changed, so the invitee's and the other guests' links keep
  working. A used link cannot be replayed anyway: its address is no longer a guest, so it matches
  nobody and answers 404 like a tampered or outdated link. The same browser gets a receipt.

## Path 2 — formal request through Joomla's own privacy component

Joomla ships a data subject request workflow in `com_privacy`: a person enters their e-mail address,
confirms it by token, and the site administrator sees an export or removal request. Privacy plugins
answer those requests for their own data.

The component ships a bundled sub-plugin `plg_privacy_appointments` that does exactly this, in the
same way `plg_privacy_j2commerce` does it for the shop. Read `.claude/skills/privacy-plugin/` before
writing it; the mechanics, the event names and the known traps are documented there.

- **Export request:** return every booking for that e-mail address, as Joomla's domain and item
  structures, with all fields including custom answers, in a readable form.
- **Removal request:** apply the same anonymisation as path 1, to every booking for that address,
  and report what was done.
- **Guest addresses:** the address is also looked up in `guest_emails` of other people's bookings,
  exactly per line after trimmed Unicode lowercasing, not full case folding (SQL only narrows the
  candidates; the sharp s remains distinct from `ss`). The export has a
  second domain, `appointments_guest_invitations`, with only the booking id, the guest address,
  the event type title, the UTC times and the state. Removal takes the address out of those guest
  lists only (audit `remove_guest` / `privacy_request`) and leaves those bookings otherwise
  unchanged. The configured legal reason blocks guest removals too.
- **Removal check:** if the operator has a legal reason to keep a booking, the plugin reports it as
  blocked rather than silently skipping it. The reason is a component setting with a free-text
  explanation, so an operator with a retention obligation can state it.

The sub-plugin is declared in the component manifest and ships its own `LICENSE.txt` in its own
`<files>` list, per `AGENTS.md`.

The standalone `plg_system_privacyrequests` can provide no-account self-submission and
confirmation through public native Core model APIs; it is not bundled or enabled by
Appointments. See its README for the exact JSON capability route and identity limits.
The Appointments callback always uses the authoritative Core request address.

Formal removal captures `#__appointments_privacy_cleanup` in the same transaction
as the irreversible booking/guest change. It keeps an authenticated-encrypted minimal
operational snapshot, indexed by Core request/booking/key, so native Remove retries
actual failed cleanup after an address match is gone. For a primary invitee the UID,
host/event IDs, UTC start/end, sequence/modified time and provider/room are required by
the conference service's stale/reused-room checks; a guest snapshot keeps only the
booking ID. Never retain erased names, addresses, answers, content, joining URLs or
calendar keys. These references remain linkable operational data, not anonymous data.
Forget a reference only after verified actual teardown/rebuild, never on a zero-match
retry, acknowledgment, expired timer or unreadable/tampered payload. Do not silently
activate a Scheduler task for them.

`#__appointments_privacy_outcomes` plus any outstanding cleanup reference govern the
bundled `content/appointmentsprivacy` native before-save veto for Core Complete.
This guard is independent of the generic system adapter and normally disabled DAV/user
plugins. A removal audit is an attempt, not success proof. Unreadable/unsearched calendar
or booking data remains incomplete; genuine unlocked key-holder access is still
required. See the component README "Formal privacy requests" for repair and separate
Core completion, and distinguish external/client/mail/backup copies from internal
cleanup. Existing booking/audit retention is not a license to discard unresolved
operational references.

## Path 3 — automatic retention

A Joomla Scheduler task runs daily and anonymises or deletes every booking whose end time is older
than the configured retention period. Default: anonymise after 365 days. The operator can set the
period per event type, because a sales call and a medical appointment do not have the same
obligations.

The task writes to the same audit table with path `retention`. It processes in batches so a large
site does not time out, and it is safe to run twice.

## What the audit table is, and what it is not

The audit table exists so an operator can prove that a deletion request was honoured. Its invariant
is narrower than "no personal data", and saying it the loose way would be wrong:

- **It holds no data of an invitee.** No name, no e-mail address, no telephone number, no answers,
  no times, not even the event type. Only the booking id, which points at a row that is gone. This
  is the audit-table invariant, and a test pins its column set. It is not a guarantee
  that every other record or external copy is gone: native Core requests/audits,
  unresolved encrypted cleanup references and other legally retained data remain
  separately governed and must not be described as anonymous.
- **It does hold one identifier of an operator**: `actor_user_id`, the Joomla user id of the
  administrator who triggered a deletion, and only for the `admin` path. That is accountability data
  about a member of the operator's own staff acting in their professional capacity, which is
  precisely what Joomla's own action log keeps in `#__action_logs.user_id`. Calling the table
  "free of personal data" while storing it would be a false guarantee, so the component does not
  make that claim.
- **It is covered by its own retention setting.** Audit rows are deleted by the same Scheduler task
  that enforces booking retention, after a separately configurable period that defaults to three
  years, so the accountability record does not become permanent either. The system check view
  reports the oldest audit row, so an operator can see what is still kept.
- **It is not exported by the privacy sub-plugin** for an invitee request, because it contains
  nothing about the invitee. A request by a member of staff about their own `actor_user_id` entries
  is answered by the operator from this table; the README says so, because an operator who is asked
  needs to know where to look.

## Visibility on the booking page

The component option `consent_mode` (`ConsentMode`) decides what the form asks: `notice` (default,
also for unknown values) shows only the privacy notice with its link, no checkbox and nothing
stored; `acknowledge` requires a "taken note" checkbox and stores nothing; `consent` requires a
consent checkbox and stores `consent_utc` and `consent_version` (see the table above). Controller
and layout derive the label text with `ConsentMode::labelText()` and the link with
`Site\Helper\PrivacyLink::resolve()`, so the stored version names the label that was shown. Joomla's
System - Privacy Consent plugin only extends com_users forms and never this form.

With `notice` the booking page shows one sentence at the foot of the information column, in every step, with the link to the site's own privacy notice (configured as a Joomla menu item or URL); without a configured notice nothing is shown. The delete link itself reaches the invitee with every mail.

With a checkbox mode (`acknowledge` or `consent`) step 2 additionally shows the checkbox and below it:

- What is collected and why, taken from a component setting so the operator writes their own text.
- A link to the site's own privacy notice, configured as a Joomla menu item or URL.
- One sentence stating that the confirmation e-mail contains a link to delete the data at any time.

Nothing here is a substitute for the operator's privacy notice, and the README says so plainly. The
component gives the operator the mechanism; the legal text is theirs.

## What the component never does

- It never stores an invitee's account password, login token or external account credential.
  Opt-in Jitsi JWT authentication uses an operator-configured signing secret and a room-scoped
  guest token in the stored joining URL, as specified in `references/conferencing.md`. These
  are conference access capabilities, not invitee account credentials; never log joining URLs.
- It never sends personal data to a third party. The conference adapters receive a room name and a
  start time, never the invitee's name, e-mail or phone number, unless the operator explicitly
  enables that for an adapter that needs it — and the setting states what is sent.
- It never writes personal data to the Joomla log or to an error message.
- **It stores no IP address at all.** The booking table has no column for one, and adding one would
  break this promise. The anti-bot timing check compares the moment the form was rendered with the
  moment it was submitted and persists nothing. The rate limiter stores only a keyed hash of the
  address, in `#__appointments_ratelimit`, where the retention task deletes rows after a day: the
  hash is keyed with `KeyProvider`, so the table cannot be turned back into a list of visitors even
  by whoever reads it.

## Tests that must exist

- A deletion link erases exactly the fields in the table above and nothing else.
- A deletion link signed for `cancel` is rejected for `erase`.
- A used deletion link is rejected.
- Deleting a future booking releases the slot, so the time becomes bookable again.
- The host notification for a deletion-driven cancellation contains no invitee data.
- The privacy sub-plugin exports and removes for an address with several bookings, and reports a
  blocked booking as blocked.
- The retention task anonymises exactly the bookings past the period, in batches, and is idempotent.
- The audit table never contains data of an invitee: a test asserts the exact column set, so an
  added column fails the suite. `actor_user_id` is the one operator identifier and is set for the
  `admin` path only; see "What the audit table is, and what it is not".
- Audit rows older than the audit retention period are deleted by the retention task.
- A guest link removes only its own address, keeps the booking and the other links valid, and a
  tampered or foreign guest link answers 404, a used one too, except in the browser session that removed the address, which sees its receipt again (HTTP 200).
- The privacy sub-plugin exports and removes an address that is a guest of another person's
  booking as a guest only, and never matches an address that merely contains it.
