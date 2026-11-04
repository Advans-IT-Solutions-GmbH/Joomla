# Internet calendars with CalDAV

An internet calendar is a calendar that every authorised user reads or writes over CalDAV, with
sign-in only. That is the name in the interface, the language files and the documentation (de-DE
«Internetkalender», fr-FR «calendriers Internet»; the backend menu entry «Internet calendars», the
site view «My calendars»). Inside the code the feature keeps the short technical name `team`:
namespaces `Helper\Team` and `Service\Team`, options `team_*`/`teamcal_enabled`, language keys
`COM_APPOINTMENTS_TEAM_*`, this file's name. Do not rename those.

Status: specification and scaffold (schema, interfaces, stubs, placeholder views). Target release
1.4.0; the token feeds it replaces are removed in 2.0.0. This file is binding for every work
package; a deviation is a change to this file in the same pull request, agreed with the maintainer.

## 1. Requirements (decided by the maintainer)

- Calendars are created in the component and shared internally or externally. CalDAV **read and
  write**. They contain personal data. Nobody may see a calendar who is not allowed to.
- **Default deny.** Everything is closed that is not explicitly granted. No secret or revocable
  addresses (capability URLs) for calendar access. The ICS token feeds contradict this and are
  replaced (section 15).
- One calendar serves one or more event types (booking pages). The booking page reveals free
  times, by intent. Bookings land in the calendar; busy times in the calendar block slots.
- **Joomla accounts only**, signed in on the website. External users are Joomla accounts in their
  own group. **A second factor is mandatory** for everybody with a calendar right.
- **Devices:** after sign-in (password and second factor), «My calendars» → «Add device». **One app
  password per device**, shown once, stored only as a hash, **without expiry**. Before creation the
  user confirms the usage rule by checkbox (device lock code, no forwarding to iCloud or Google
  calendars, report a loss at once); the confirmation is logged. **A mail to the user for every new
  device.** The administrator sees «last synchronised» per device. The Joomla password never works
  for CalDAV (it would bypass the second factor).
- **Automatic refresh:** CTag and sync token (RFC 6578) correct, instructions per client with the
  refresh interval. Clients: classic Outlook (Office LTSC 2024) with the CalDav Synchronizer
  add-in (highest priority), the iOS calendar, Android with DAVx⁵, Thunderbird, eM Client.
  `/.well-known/caldav` works. The new Outlook for Windows and the Outlook mobile apps are not
  supported (they speak no CalDAV); the documentation says so.
- **Roles per calendar:** free/busy, read, write (view, create, change and delete), manage.
  Managers share with the team; only administrators share with externals. External grants have no
  expiry.
- **Every account is created by an administrator**, internal and external; Joomla's registration
  stays off. A one-time activation link, valid 72 hours and bound to the account's address, lets the
  person set the password; the second factor follows at the first sign-in. On completion the
  administrators get a mail with a one-step revoke. No administrator confirmation.
- **Log:** sign-ins and changes only (no read access), 12 months, hash chain, checksum visible in
  the administrator only.
- **Failed sign-ins:** temporary lock after 10 failures with a growing delay, never permanent.
- **Blocking a user:** clean-up mode. The device sees the calendar as deleted on its next
  synchronisation and removes its local copy; after that it is blocked completely. Tested per client.
- **Encryption B:** calendar contents are encrypted on the server so that only authorised users can
  decrypt them. One recovery key for the whole site, kept offline by the owner as two printouts in
  separate places. After a password reset a manager grants again, or the recovery key is used.
- **Retention per calendar:** entries older than the calendar's retention are deleted by the
  Scheduler; the log records the count, never content.
- **Privacy requests through Joomla's own privacy component** (com_privacy, export and removal
  requests) via `plg_privacy_appointments` (section 9.1). No separate search in the administrator and no
  blind index of e-mail addresses (whoever holds the server key could test known addresses against it).
- **Booking rows at rest:** the personal fields of bookings (names, e-mail, phone, guests, answers)
  are encrypted with a server key like the times; reminders, links, exports keep working; a search by
  e-mail address decrypts on the server (section 5.10).
- **Any host:** the component runs wherever Joomla 5.4 or 6 runs, at any hosting provider, without
  provider-specific code. Everything a host can block is checked by the system check and reported with
  a remedy; the README lists the prerequisites (section 8.3).
- **Private smartphones are allowed** for internet calendars. No device management is required; the
  usage rule (lock code, no forwarding, report a loss) and the clean-up are the safeguards.
- **Mails as today:** content and ICS of booking mails also go to the team; they are outside the
  calendar encryption.
- Generic: no installation-specific value anywhere. Everything is a setting.

## 2. Threat model: what encryption B protects, and what not

Protected:

- **A copy of the database alone** (dump, backup, SQL injection in any extension): no calendar
  content, no times, no app password test, no key. Contents need a user key; times and every hash
  need a server key derived from the site secret in `configuration.php`, which is not in the
  database.
- **A manipulated database alone**: grants, public keys, devices, calendar keys and invitations
  carry a `row_mac` (HMAC with a server key). A row added or changed without the site secret fails
  verification and grants nothing. Without this, an attacker who can write one table could insert a
  grant, swap a user's public key and receive the calendar key with the next key completion.
- **Administrators without a grant**: a Super User sees calendar lists and the log, never contents.
  A Super User can still grant themselves access; every grant is logged and mailed to the calendar's
  owner and managers, so it cannot happen silently.

Not protected, stated plainly. **In one sentence: the encryption protects when the database or a
backup leaks, not when somebody executes code on the server.** End-to-end encryption was considered
and rejected by the maintainer: whoever controls the server controls everything it serves.

- **Code execution on the server** (a compromised extension, the hosting provider, an attacker with
  file access): while a key holder's request runs, the server holds the calendar key in memory, and
  such an attacker can also capture app passwords. CalDAV clients cannot do end-to-end encryption.
- **Database and files together** (a full hosting backup): the times of all objects become readable
  (server key), contents stay encrypted.
- **Devices that never connect again** keep their local copy. The clean-up only reaches devices that
  synchronise. This is why the usage rule requires a device lock code.
- **Booking mails** to the team carry content and ICS in clear, as decided (outside the encryption).

## 3. Architecture

```
com_appointments (component, existing)
├── administrator/…/src/Helper/Team/      pure value objects and crypto primitives (frozen contracts)
├── administrator/…/src/Service/Team/     service interfaces + one implementation class each, wired by TeamServices
├── administrator/…/lib/                  bundled sabre/dav, namespace-prefixed (work package A)
├── plugins/system/appointmentsdav/       CalDAV endpoint: /.well-known/caldav and /dav/
├── plugins/user/appointments/            sign-in, sign-out, account save/delete → key session, lock-out, clean-up
├── plugins/privacy/appointments/         existing; extended for staff data (work package F)
└── plugins/task/appointments/            existing; new routines: clean-up advance, log prune, invitation purge
```

- Both new plugins are installed **disabled**; the component option «Internet calendars» is off by
  default. `TeamServices::enabled()` is the single switch: option on **and** libsodium available.
  Every entry point behaves as if the feature did not exist while it is false.
- `TeamServices` is the only wiring point. Each service is an interface plus one class with a fixed
  name. A work package replaces the bodies of its own classes only. Stubs refuse with
  `TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501)`.
- The system plugin takes over a matching request in `onAfterInitialise`, before routing and before
  a session cookie, and closes the application afterwards.
- **URL layout** (relative to the site root, path configurable, default `dav`):
  `/.well-known/caldav` → `301` to `/dav/`; `/dav/principals/u<id>/`; `/dav/calendars/u<id>/`
  (calendar home); `/dav/calendars/u<id>/<calendar uri>/<object>.ics`. The principal uses the
  numeric user id, never the user name. The calendar URI is 32 random hex characters, identical in
  every user's home; it is not a secret.

## 4. Data model

The SQL is in `sql/install.mysql.utf8.sql` (end of file) and identical in
`sql/updates/mysql/1.4.0.sql`; the header comments there are part of this specification. If the
release number differs from 1.4.0, rename the update file and adjust `01-installation.php`.

| Table | Purpose |
|---|---|
| `#__appointments_keyring` | Public half of the site recovery key (`purpose = 'recovery'`) |
| `#__appointments_user_keys` | Per user: X25519 public key, secret key wrapped with the password key |
| `#__appointments_calendars` | Calendar record, sync token, current key version, `rotation_due` |
| `#__appointments_calendar_keys` | Per calendar and key version: sealing key pair, recovery wrap |
| `#__appointments_calendar_grants` | Default-deny grants: role, state, audience |
| `#__appointments_calendar_key_wraps` | Calendar key version sealed to one user |
| `#__appointments_calendar_objects` | Encrypted objects, encrypted time skeleton, `booking_id` |
| `#__appointments_calendar_changes` | Sync journal (RFC 6578) |
| `#__appointments_calendar_busy` | Encrypted busy cache for the booking page |
| `#__appointments_devices` | Devices: app password hash, wrapped user key, state, last sync |
| `#__appointments_device_calendars` | Per device and calendar: last sync, clean-up delivered |
| `#__appointments_invitations` | External invitations: token hash, 72 h, state |
| `#__appointments_teamlog`, `#__appointments_teamlog_head` | Hash-chained log and its head/lock |
| `#__appointments_auth_failures` | Lock-out counters per keyed bucket |
| `#__appointments_eventtype_calendars` | Further busy calendars of an event type |
| `#__appointments_eventtypes.calendar_id` | Target calendar of an event type (NULL = none) |

State values are constants in `Helper/Team/TeamStates.php`; roles in `Helper/Team/CalendarRole.php`;
log events in `Helper/Team/AuditEvent.php`. Nobody writes a literal state number.

`eventtypes.calendar_id` has no foreign key on purpose (the event types table predates the
calendars); `CalendarService::delete()` sets it to NULL.

The schema is frozen for the parallel work. A package that needs a schema change asks the
coordinator; the change is made once, in both SQL files and in `02-structure.php`/`20-uninstall.php`.

## 5. Encryption

All primitives are in `Helper/Team/CipherSuite.php` (libsodium). Nothing else calls `sodium_*`
directly, except `SecretValue::wipe()`.

### 5.1 Server keys

Derived by `KeyProvider` from the site secret, one per purpose (`PURPOSE_TEAM_*`): app password
hash, device key derivation, password key derivation, timing, row MAC, UID hash, log chain, lock-out
buckets, invitation token hash. **Changing the site secret invalidates all of them**: every app
password, every time skeleton, every MAC, every encrypted booking field. The recovery key alone does
not repair that: `recoverKeyring()` trusts a calendar key row only with a valid MAC, otherwise a
database writer could slip in a calendar key of their own (anybody can seal to the public recovery key).
The way back is the old site secret. The site secret is therefore part of the recovery material and is
printed with the recovery key (section 5.6). The README says so; the system check warns when the
secret's fingerprint changed.

### 5.2 Key hierarchy

```
recovery key pair (X25519, secret offline)            user key pair (X25519) per user
        ▲ sealed                                       ▲ wrapped by password key (Argon2id + server key)
        │                                              ▲ wrapped by device key  (HKDF of app password + server key), per device
calendar key CK_v (32 bytes, XChaCha20-Poly1305) per calendar and version
        ├── sealed to every key-holding grantee's public key  (#__appointments_calendar_key_wraps)
        ├── sealed to the recovery public key                 (#__appointments_calendar_keys.recovery_wrapped)
        └── wraps the calendar sealing key pair secret         (#__appointments_calendar_keys.box_secret_wrapped)
calendar sealing key pair (X25519) per version: public key in clear → booking flow seals objects
```

Formulas (normative):

- Password key: `s = sodium_crypto_pwhash(32, password, salt16, OPSLIMIT_INTERACTIVE, MEMLIMIT_INTERACTIVE, ARGON2ID13)`;
  `K = hash_hkdf('sha256', s . key(TEAM_PASSWORD_KEK), 32, 'com_appointments/password-kek/v1', salt16)`.
  INTERACTIVE (64 MiB) because shared hosts limit memory; limits are stored per row so they can be
  raised later.
- Device key: `AppPassword::wrappingKey()` (implemented). App password hash: `AppPassword::hash()`.
- Wraps of secret keys (user secret under password or device key; sealing secret under CK):
  `CipherSuite::encrypt(K, secret, associatedData(<purpose>, <ids…>))` with purposes
  `user-key/password` (user id, key version), `user-key/device` (user id, key version,
  `bin2hex(kek_salt)`: the salt is unique per device and covered by the device MAC, so no device id
  is needed), `calendar-box` (calendar id, version).
- Row MAC encoding (only `KeyService::rowMac()` computes it; nobody builds an HMAC of their own): the
  table name is normalised (`#__appointments_x`, `appointments_x` and `x` are the same; an unknown table
  throws), fields sorted by name, `null` as `N`, any other value as `V` plus the string value, booleans
  as 0/1. A mismatch means «no key» and is logged as `key.mac_failure` (table without prefix, reason
  code, never values).
- Wraps of CK: `CipherSuite::seal(publicKey, CK)`; on open, the plaintext must be exactly 32 bytes.
- Object (scheme 1): `encrypt(CK_v, ics, associatedData('object', calendarId, uri, v))`.
- Object (scheme 2, booking flow): `seal(boxPublicKey_v, envelope)` where the envelope is
  `"ACv1\n" . json_encode(['c' => calendarId, 'u' => uri, 'm' => base64(hmac)]) . "\n" . ics` with
  `hmac = hash_hmac('sha256', associatedData('envelope', calendarId, uri, ics), key(TEAM_ROW_MAC), true)`;
  on open the calendar id and URI must match the row and `m` must verify, otherwise the object is
  refused (anybody can seal to the public box key, only the server can produce `m`).
  Sealed objects are re-encrypted to scheme 1 by the next key holder's
  request (`CalendarObjectStoreInterface::reencrypt()`), without changing ETag or sync token.
- Time skeleton: `encrypt(key(TEAM_TIMING), skeleton, associatedData('timing', calendarId, uri))`.
- Busy cache: `encrypt(key(TEAM_TIMING), json, associatedData('busy', calendarId, synctoken, window_start_utc, window_end_utc))`.
- UID hash: `hash_hmac('sha256', calendarId . "\0" . uid, key(TEAM_UID), true)`.
- Row MAC: `hash_hmac('sha256', associatedData('row', table, field1, value1, …), key(TEAM_ROW_MAC), true)`
  over the security-relevant columns. The field lists come from one static method per owner and
  are used by everybody: `GrantService::macFields()` (calendar_id, user_id, role, state, audience,
  granted_by), `CalendarService::macFields()` (id, uri, owner_user_id, allow_external,
  state; deliberately not key_version, rotation_due, synctoken, which B and A change without a new MAC),
  invitations (token_hash, user_id, email_hash, state, invited_by, expires_utc, completed_utc),
  and the key, device and keyring tables in `KeyService`/`DeviceService`. A grant in
  `GRANT_PENDING_KEY` with a wrap of the current version counts as effective; nobody has to flip it to
  active.

### 5.3 Where keys exist

- A **CalDAV request** of an active device: app password → device key → user secret key → wraps →
  calendar keyring. Everything lives in `SecretValue` objects for the request only.
- A **web session**: at password sign-in the password unlocks the user key (`KeySessionInterface::onLogin`).
  The key is kept in the Joomla session encrypted with a random 32-byte session key; the session key
  is in a separate cookie (`HttpOnly`, `Secure`, `SameSite=Strict`, session lifetime, separate names
  for site and administrator). The key is released only after the session passed the second factor.
  The key is unlocked only inside Joomla's own sign-in (`CMSApplication::login`); no page offers «enter
  your password to unlock». A sign-in without password (passkey, remember-me) leaves it locked: actions that need it answer 423
  with «please sign in with your password».
- **Nowhere else.** The booking flow, the Scheduler, the privacy plugin and the system check never
  hold a user or calendar key.

### 5.4 Grants and key completion

A key-holding grant (read, write, manage) needs a wrap of the current version. When the actor's key
is unlocked, the wrap is written at once (sealing needs only the grantee's public key). Otherwise
the grant waits in state `GRANT_PENDING_KEY`, and **any** request that holds the keyring (a member's
device sync, a manager's web action) completes it (`KeyServiceInterface::completePendingWraps`).
Suspended grants are never completed automatically. A grantee without a key pair (never signed in
since the feature was enabled) stays pending until their first sign-in creates one.

### 5.5 Revocation and rotation

Revoking a key-holding grant deletes the user's wraps of every version and marks `rotation_due`. The
next key holder's request (or the revoking manager, if unlocked) creates version `v+1` (new CK and
sealing pair, wraps for every remaining holder and the recovery key). New writes use `v+1`; old
objects are re-encrypted in batches. Rationale: CK never leaves the server, so a revoked user cannot
decrypt anyway; rotation protects against somebody who kept an old database copy plus an app
password.

### 5.6 Recovery key

One for the whole site. Created once by a user with `core.admin` (backend, unlocked session):
X25519 pair, the secret shown once as Base32 groups with a fingerprint, printed **twice, together with
the site secret** (5.1), and the two printouts kept offline in separate places; only the public key is
stored.
Calendars cannot be created before it exists. Use: an administrator enters the secret in a POST form
(never stored, never logged); the server unseals the chosen calendars' keys and wraps them for the
chosen users. Rotation needs the old secret. Losing the recovery key and every key holder of a
calendar means losing that calendar's contents: by design.

### 5.7 Password change and reset

- Change by the same user in an unlocked session (profile edit): rewrap with the new password.
- Anything else (reset by e-mail, password set by an administrator, unlocked session missing):
  `resetUserKey()`: new key pair, all wraps of the user deleted, all grants `GRANT_SUSPENDED`, all
  devices into clean-up, mail to the user and to the managers concerned. A manager re-grants, or an
  administrator uses the recovery key. This is the requirement, and it also means that a password
  reset by e-mail can never give anybody calendar access on its own.

### 5.8 Decision: time ranges are readable by the server

**Trade-off, stated explicitly.** The booking page must hide busy slots while no key holder is
present, and the free/busy role must work without a calendar key. The server therefore needs the
times of every object without a user key. Options:

| Option | Booking page and free/busy | Leak |
|---|---|---|
| Times in clear columns | works, indexable | a database copy alone shows everybody's busy pattern |
| **Times encrypted with a server key (chosen)** | works; no SQL time filter, a per-calendar encrypted busy cache instead | database copy alone: nothing; database and files together: busy pattern (when, how long, recurrence, free/busy flag); never title, place, attendees, description |
| Times under the calendar key | booking page cannot block calendar busy times while nobody is signed in, free/busy needs a key → **contradicts requirements** | — |

What the server can read: per VEVENT exactly DTSTART, DTEND/DURATION, RRULE, RDATE, EXDATE,
RECURRENCE-ID and STATUS/TRANSP/X-MICROSOFT-CDO-BUSYSTATUS; every line rebuilt from a validated value,
no group and no parameter except VALUE=DATE, TZID and RANGE, TZID replaced by the IANA identifier it
safely resolves to (otherwise the time is floating), no VTIMEZONE of the client. Not the UID. An RRULE
outside RFC 5545, or one that cannot be resolved finitely (BYYEARDAY with BYDAY, BYWEEKNO with an
ordinal BYDAY), makes `extract()` fail with 400, and the PUT is refused with 400. `redacted()` writes
its own VTIMEZONE from PHP's zone data (1970 to 2050). The busy pattern within the booking horizon is public anyway (the booking page shows free
times); history and the time beyond the horizon are the additional exposure.

### 5.9 Conflicts of encryption B with other requirements (reported, not resolved silently)

1. **Bookings table.** Resolved by the maintainer: the personal fields are encrypted with a server key
   (5.10), so reminders, links, the privacy workflow and retention keep working without a user key.
2. **Privacy requests of invitees.** Resolved by the maintainer: Joomla's privacy workflow finds
   bookings by decrypting them on the server, and calendar entries by decrypting them in the session of
   a key holder (section 9.1); old entries go by the per-calendar retention.
3. **Sign-in without password** (passkeys) cannot unlock keys; such users need a password sign-in
   for key operations (adding a device, granting). Normal CalDAV use is unaffected.
4. **Free/busy and the booking page** need server-readable times: section 5.8.

### 5.11 Replay protection

A row MAC proves that the server wrote a row, not that it is the newest one: a database writer could
play back an older, validly signed grant, calendar, device or invitation row.

- `#__appointments_calendars.grant_epoch` is part of the calendar MAC and of every grant MAC of that
  calendar (`GrantService::macFields()` takes it from the calendar row, together with the grantee's
  `user_keys.key_version`). C raises it, under `SELECT … FOR UPDATE` on the calendar row, at every
  revocation, downgrade, suspension, switch-off of `allow_external` and key rotation, and recomputes
  the MACs of the remaining grants in the same transaction. A played-back grant no longer verifies.
  B rotates and completes wraps only after locking the same calendar row, so a revocation and a
  rotation never interleave.
- `#__appointments_user_keys.device_epoch` is part of the user key MAC and of every device MAC of that
  user. D raises it at every revocation and clean-up step of any of the user's devices and recomputes
  the MACs of the remaining devices: a played-back device row with an old app password is invalid.
- Against a rollback of the database **together** with these epochs, `EpochAnchorInterface` (B) keeps
  the highest epoch per calendar and per user in a signed file below the Joomla directory; a row is
  valid only when its epoch is at least the anchored one. Without a writable file the feature works
  without this protection and the system check says so. Restoring files and database together is a
  legitimate rollback.
- Activation links cannot be anchored; `complete()` therefore works only for an account that has never
  signed in, and every completion mails the administrators with the revoke link.

### 5.10 Booking rows at rest (work package E, second part)

- Encrypted with `key(PURPOSE_BOOKING_DATA)`: `invitee_name`, `invitee_first_name`, `invitee_last_name`,
  `invitee_email`, `invitee_phone`, `guest_emails`, `custom_fields`, `cancel_reason`, `conference_url`.
  Value format `v1:` plus Base64 of `CipherSuite::encrypt(key, value, associatedData('booking', uid, column))`;
  the booking `uid` exists before the insert, so the row id is not needed. Readers accept a value without
  the prefix as clear text during the migration only.
- **No blind index** (maintainer's decision): a keyed hash of an address could be tested against known
  addresses by whoever holds the server key. A lookup by address (privacy plugin, booking list filter)
  decrypts the candidate rows on the server in batches and compares in memory. Free-text search over
  names in the booking list works the same way, paged.
- One place reads and writes these columns (a booking record class of E); no other code selects them
  directly. CSV export, mails, reminders, signed links, the privacy plugin and the anonymiser use it.
- Migration in 1.4.0: column types become TEXT/MEDIUMTEXT (`custom_fields` no longer JSON), the
  e-mail index on `invitee_email` is dropped; then `script.php` encrypts
  the existing rows in batches (idempotent, rows with `v1:` are skipped) through E's helper. The SQL
  is written by the coordinator from E's exact proposal, after the booking page branch is final.

## 6. Authentication

- CalDAV: HTTPS only. A plain HTTP request is refused with 403, without a body and without a redirect,
  **before the Authorization header is read**. The header and the password are never logged and never
  appear in an error message or a response. The system check recommends HSTS for the site. HTTP Basic, user name = Joomla user
  name (case-insensitive), password = app password. `AppPassword::parse()` rejects anything that is
  not 32 Base32 characters before any database access, so a Joomla password is never even looked up.
  Lookup by `secret_hash` (unique), then the user name must match, the account must not be blocked
  (except in clean-up, which needs no key), and an active device's user must have a second factor
  (otherwise the empty view, section 11).
- The `Authorization` header: read `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then
  `PHP_AUTH_USER/PW`. Joomla's `htaccess.txt` passes it on through
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`; the system check tests it.
- Failures answer `401` with `WWW-Authenticate: Basic realm="<site name>", charset="UTF-8"`, the same
  for every reason. A lock answers `429` with `Retry-After`, also for a correct password.
- **Second factor, enforced by Joomla:** Joomla 5.4 and 6 both have the com_users option
  `forceMFAUserGroups` (Users → Options → Multi-factor Authentication → «Enforce MFA for these user
  groups»): members of a listed group must set up a second factor at their next sign-in before they can
  do anything else. The installer adds both team groups to it (section 7.1); it never removes an entry.
  `neverMFAUserGroups` must not contain them; the system check reports it as an error. Note
  `mfaonsilent` (default off): a «remember me» or passkey sign-in skips the second factor in Joomla;
  such a session never unlocks keys (5.3), so it cannot add devices or grant.
- **Second factor, checked by the component:** `GrantServiceInterface::hasSecondFactor()` reads Joomla's
  MFA records (`#__user_mfa`): an enabled method other than backup codes and other than the e-mail
  method (the e-mail method is the same channel as the password reset, so a reset by mail alone would
  reach the calendars). An account that Joomla's own rules exclude from MFA (`neverMFAUserGroups`)
  has no effective grant. Grants are effective only with it.
  «Add device» requires it at that moment, in a session that passed it. **Every CalDAV request** of an
  active device whose account has no active second factor is refused with 403 (logged
  `dav.auth_failure`, reason `no_mfa`) before any calendar is touched; devices in clean-up are not
  affected, because they receive no data.
- **Lock-out** (`LockoutServiceInterface`): CalDAV and website sign-ins of calendar users. On the
  website the lock is checked **before** Joomla checks the password (`guardLogin()` from the system
  plugin in onAfterRoute, for site `com_users` `user.login` and administrator `com_login` `login`)
  and answered exactly like a wrong password, so the lock is no oracle and no password is tried while
  locked; `onUserAuthorisation` stays as a second safeguard only (Joomla then shows its own
  JLIB_LOGIN_DENIED; that path is reached only if `guardLogin()` did not run). `retryAfter()` is called before every
  check of a secret, then exactly one `recordFailure()` or `recordSuccess()`. Counter buckets are
  keyed hashes (IPv6 per /64); no user name or address in clear.

## 7. Roles and grants

| Role | CalDAV | Key | Web |
|---|---|---|---|
| Free/busy | read-only calendar of redacted busy blocks (SUMMARY «Busy» in the user's language, CLASS CONFIDENTIAL) | none | sees the calendar in «My calendars» |
| Read | read | yes | same |
| Write | view, create, change, delete (GET/REPORT, PUT, DELETE) | yes | same |
| Manage | as write | yes | grant to team members (up to manage), revoke, edit title/colour/description, rotate |

- Owner: the user named at creation; gets a manage grant. Ownership changes only through an
  administrator.
- Externals: only users with the component permission `appointments.team.external` grant (section
  7.1), only on calendars with `allow_external = 1`, at most write.
  A user in the external group is external even if also in a team group.
- Accounts and activation (`InvitationServiceInterface`): an administrator creates every account in
  Joomla's user management (team group or external group; Joomla's registration stays off), grants
  calendars, and sends the activation link: one-time, 72 hours, bound to the account and to a keyed
  hash of its address; only a keyed hash of the 32-byte token is stored. The site page
  (`view=activation`, work package D) only sets the password; Joomla enforces the second factor at
  the first sign-in. On completion every user with `appointments.team.external` gets a mail with a
  link to the backend page that revokes the access in one step (all grants removed, account blocked,
  device clean-up). There is no administrator confirmation and no self-registration.
- No sharing over CalDAV (no `share`/`invite` POST, no `MKCALENDAR`, no `ACL`). PROPPATCH changes only
  per-user display properties (colour, order), stored in `client_props`; anything else 403.

### 7.1 Groups, access level and administrators

| Item | Created by the installer | Meaning |
|---|---|---|
| Group «Internet calendars internal» (de-DE «Internetkalender intern») | below the registration group (`com_users` `new_usertype`, normally «Registered»), no permission of its own | may receive team grants; second factor enforced |
| Group «Internet calendars external» (de-DE «Internetkalender extern») | same | may receive grants from administrators only, at most write; second factor enforced |
| Access level «Internet calendars» | holds both groups | access of the «My calendars» menu item, so the item is shown to these accounts only |
| Component permission `appointments.team.external` «Share with external users» | in `access.xml`, granted to nobody by default (Super Users have it implicitly) | send activation links to external accounts, receive the revoke mail, grant to external users |

- A group is **eligibility, not access**: it says that the account may hold calendar grants and must
  use a second factor. What it may do with which calendar is only in `#__appointments_calendar_grants`.
  An account outside both groups holds no effective grant.
- The groups are created on installation and update when the configured group is missing, unless
  the option `team_create_groups` is off; existing groups with the same title below the same parent
  are reused. Their ids are stored in `team_groups`, `external_group` and `team_viewlevel`. Group
  titles come from the language of the installation run. Uninstalling leaves groups and level in
  place, because they may hold accounts.
- «Administrator» in this specification means the permission `appointments.team.external`; the
  recovery key and the backend views `devices` and `teamlog` need `core.admin` on the component.
  Calendar creation needs `core.create`.
- Private smartphones are allowed; nothing in the component distinguishes device ownership.

## 8. CalDAV behaviour (work package A)

- sabre/dav server with: `Auth\Plugin` (own backend), `DAVACL\Plugin` (principals of the user only;
  `principal-property-search` and `principal-search-property-set` disabled; `hideNodesFromListings`),
  `CalDAV\Plugin`, `CalDAV\ICSExportPlugin` **off**, `DAV\Sync\Plugin` (RFC 6578), `CalDAV\Schedule`
  **off** (no iTIP mail from the server), `Browser\Plugin` **off**, `Locks` **off**, `PropertyStorage`
  **off**.
- Calendar home lists exactly the effective accesses (`GrantServiceInterface::calendarsFor()`) in
  the view the clean-up service returns. Supported component set: VEVENT only.
- Calendar properties: `displayname`, `calendar-color`, `getctag` (= sync token), `sync-token`,
  `supported-calendar-component-set`, `current-user-privilege-set` from the role.
- Object limits from `CalendarObjectStoreInterface`; `no-uid-conflict` via `uid_hash`;
  `If-Match`/`If-None-Match` honoured.
- Every write: one transaction, sync token +1, change row, log row (`object.*`, no content).
  Read requests write nothing except the throttled «last synchronised».
- `/.well-known/caldav` answers every method with `301 Location: <root>/dav/`.
- Responses: `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, no Joomla session
  cookie. Site offline mode does not stop CalDAV (team synchronisation continues).

### 8.1 Hosting (Apache, shared hosting)

To verify on the dev installation before anything else (curl, in this order): `OPTIONS`, `PROPFIND`
(Depth 0 and 1), `REPORT`, `PUT`, `DELETE`, `MKCALENDAR` (must reach PHP and get 403 from sabre, not
405 from Apache), `Authorization` header present in PHP, `/.well-known/caldav` reaching Joomla. Known
risks: a web application firewall or ModSecurity rule set of the host that blocks WebDAV methods or
XML bodies; a host rule that serves `/.well-known/` itself; a security extension in Joomla (WAF) that
blocks unknown methods. Clients can always be configured with the full `/dav/` address, so a
non-working well-known address degrades discovery only. No change to the operator's `.htaccess` is
required if it is Joomla's `htaccess.txt`.

### 8.3 Runs on any Joomla host

No code for a particular provider, no provider name in code or documentation. What a host can block
is checked by the system check (F) and reported in plain language with the remedy; the README lists
the prerequisites:

| Check | How | Remedy shown |
|---|---|---|
| HTTPS | the site's own URL and a loopback request | enable TLS for the site |
| WebDAV methods reach PHP | the system check sends OPTIONS, PROPFIND, REPORT, PUT, DELETE, MKCALENDAR to the DAV address of the site itself and expects sabre's answers (MKCALENDAR 403 from sabre, not 405 from the web server) | allow the methods in the web server or firewall rules (ModSecurity/WAF) |
| `Authorization` header reaches PHP | loopback request with a test header | Joomla's `htaccess.txt` rule `E=HTTP_AUTHORIZATION`, or the host's setting |
| `/.well-known/caldav` reaches Joomla | loopback request | rewrite rule, or configure clients with the full DAV address |
| libsodium with Argon2id | `CipherSuite::available()` and a test `sodium_crypto_pwhash` | PHP with the sodium extension |
| Memory for Argon2id INTERACTIVE (64 MiB) | `memory_limit` | raise `memory_limit` to at least 128M |
| HSTS | `Strict-Transport-Security` on the site's own answer | recommendation: enable HSTS in Joomla's HTTP headers plugin or the web server |

The CalDAV base address is the system plugin's parameter `dav_base_url` (absolute https URL; default:
the site root plus `/dav/`), read by everybody only through `TeamServices::davBaseUrl()`, which always
ends in `/`; the routing path, the well-known redirect, the client instructions, mails and the system
check derive from it. There is no separate path parameter. A configured address is used only when it
passes `TeamServices::acceptDavBaseUrl()` (https, host, no credentials, query or fragment, at most 255
characters, below the site root's path with 1 to 3 safe segments, the first not a Joomla folder);
otherwise the default applies. A 401 of the CalDAV handler carries
`X-Appointments-Authorization: received` when an `Authorization` header reached PHP, so the system
check can test the header path anonymously; every method authenticates first. Calendar data is reached only through
the `Service/Team` interfaces; the booking page reads busy times only through
`BookingCalendarBridgeInterface::busyFor()`.
### 8.2 Bundling sabre/dav

- sabre/dav **4.7.1** (BSD-3-Clause; needs sabre/vobject 4, sabre/event 5, sabre/xml 2,
  sabre/http 5, sabre/uri 2, psr/log), PHP 8.1 compatible.
- Joomla's shared build excludes every folder named `vendor`, so the libraries are committed under
  `administrator/components/com_appointments/lib/`, **prefixed with php-scoper** to
  `Advans\Component\Appointments\Vendor\` (`VendorAutoloader::PREFIX`). Reason: other Joomla
  extensions ship sabre/vobject or sabre/dav too (calendar extensions do), and two unprefixed copies
  in one PHP process would load whichever comes first. psr/log is prefixed as well, so Joomla's own
  copy is never touched.
- Watch: sabre/vobject and sabre/dav name classes in strings (component and value maps, XML element
  maps). The scoper configuration needs patchers for them; a unit test loads every mapped class name
  of the prefixed copy.
- The generator (composer.json/lock, scoper config, script) lives in `tests/tools/sabre/`, which the
  package build excludes; the CI job regenerates `lib/` and fails on any difference.
- `THIRD-PARTY-NOTICES.txt` lists every bundled library with version and licence; upstream headers
  stay unchanged. The «no ß» lint and the deprecated-API scan run over `lib/` too; fix by patcher, not
  by allowlist, where possible.

## 9. Log (work package F)

`AuditServiceInterface` defines the chain. Logged: sign-ins of calendar users (success, failure,
lock), CalDAV authentication failures and locks, every change to devices, calendars, grants,
invitations, keys, objects (without content), booking writes, clean-up steps, pruning. Not logged:
any read. `details` holds ids, role names, rule version, reason codes; never content, never a
secret, never invitee data; addresses only as keyed hash. Retention: 12 months (`TeamStates`), pruned
by the Scheduler; the head row keeps the hash of the last pruned row so the remaining chain still
verifies. The backend log view shows the events, a «verify chain» button and the head hash
(checksum). The checksum is never shown on the site.

### 9.1 Privacy requests (work package F, with the booking page's privacy part)

`plg_privacy_appointments` answers Joomla's export and removal requests (com_privacy). There is no
index of e-mail addresses anywhere (maintainer's decision, see 5.10).

- **Bookings:** encrypted with the server key, so the plugin decrypts them on the server in batches and
  matches invitee and guest addresses; export and removal as before.
- **Calendar entries:** searched only in the session of the person handling the request, and only in
  the calendars that person can unlock (`KeySessionInterface::current()` and `openKeyring()`; the owner,
  a manager, or keys recovered with the recovery key). The objects are decrypted in memory and matched
  with `TimingIndexInterface::emails()`. Removal deletes the matching objects as normal changes.
- **Never silently incomplete:** every calendar that could not be searched (no key in this session) is
  listed by title in the export and in the removal result, with the note that it was not searched and
  how to search it (sign in as a key holder, or use the recovery key).
- Staff data (devices, grants, log rows of a staff member) is exported and removed by the same plugin.
- Every search, export and removal is logged (`privacy.search`, `privacy.export`, `privacy.delete`)
  with counts only, never the address and never content.
- The plugin's event handlers belong to the booking page's privacy part; F adds its calls there with
  that work's consent.
## 10. Devices and «My calendars» (work package D)

Site view `mycalendars` (signed in, feature on; otherwise 404): calendars with role, devices with
client, state and last synchronisation, «Add device» (name, client, checkbox with the usage rule
text and its version, `TeamStates::DEVICE_RULE_VERSION`), «Remove device» (starts the clean-up). The
created page shows the app password once (copy button, no autocomplete, `Cache-Control: no-store`),
the server address, the user name and the client-specific instructions (section 16). The app
password is never in a URL, a log, a mail or the session.

## 11. Blocking and clean-up (work package F)

States in `TeamStates`: active → clean-up empty → clean-up hidden → revoked.

1. **Empty:** every calendar is served with no objects; `sync-collection` reports every URI the
   device may know (current objects and deletions since its token) as deleted; the CTag changes
   (`<token>-c`); writes are refused with 403. Delivered when the device fetched the collection
   (`markDelivered`).
   The calendars listed are `CleanupServiceInterface::calendarsInCleanup()`: every calendar the device
   synchronised before (`#__appointments_device_calendars`, written by `DeviceService::touch()` at the
   first synchronisation of each calendar, without throttling) that still exists, even when the grant is
   already suspended.
2. **Hidden:** the calendar home lists nothing, so the client removes the calendars.
3. **Revoked:** 401 for every request.

Each phase ends when delivered or after `team_cleanup_days` (option, default 14). Triggers: the
user's «remove device»; an administrator's «block device» or «block calendar access»; Joomla account
blocked; password reset. An account without an active second factor is refused outright (section 6).
Grants of a blocked user are suspended. Client behaviour per client is part of the manual test plan, because clients
differ (Outlook with CalDav Synchronizer in two-way mode must delete the Outlook items, not upload
them again; one-way «Outlook → server» would try to upload, which the refused writes stop).

## 12. Mails (work package D)

`TeamMailerInterface`: new device (always), activation link, activation completed (to the
administrators, with the one-step revoke link), grant changed (to owner and managers), access
suspended, calendar deleted (to the former owner and managers, `calendarDeleted()`). They are DB templates (`#__appointments_templates`, eventtype_id 0, no ICS), editable
under Templates. Joomla mailer, recipient language, no secret except the activation link. Booking mails stay as they are, with content and ICS to the team.

## 13. Administrator views

- Backend menu: submenu «Dashboard» and «Internet calendars» (view `calendars`), in the manifest.
- `calendars` (C): list, edit (title, description, colour, owner, allow externals, blocks
  availability, retention), grants, rotate key, recovery key setup and use. Navigation links on the
  dashboard (C).
- Retention per calendar (F): Scheduler routine «calendar retention» calls
  `CalendarObjectStoreInterface::deleteEndedBefore()` for each calendar with `retention_days > 0`.
- `invitations` (C, `appointments.team.external`: activation links, the revoke page), `devices` (F: all devices, «last synchronised», block with clean-up, revoke),
  `teamlog` (F: events, verify, checksum).
- System view (F): libsodium, HTTPS, Authorization header, well-known reachability, plugins enabled,
  MFA enforcement, recovery key present, site secret fingerprint changed.
- `DisplayController::EDIT_VIEWS` gets the new edit views (C).

## 14. Booking page and calendars (work package E)

- Event type form: target calendar and further busy calendars. Only a calendar that the editing person
  **and** the host reach effectively can be bound; for the target calendar both need write. A Super
  User without a grant therefore binds nothing until a manager grants. Otherwise anybody with the
  event type permission would get the free/busy view of any host's calendar.
- Bookings of different hosts that share a calendar are serialised by
  `BookingCalendarBridgeInterface::lockForBooking()` inside the booking transaction; `busyFor()` also
  reads the bookings of other hosts whose event types write into one of the calendars.
- `removeOrphans()` removes calendar copies of cancelled, anonymised or deleted bookings (no key
  needed); the bridge calls it after each booking change and the Scheduler routine of F in batches.
- `AvailabilityService` adds `BookingCalendarBridgeInterface::busyFor()` to the host's busy periods,
  also inside the locked booking transaction (`readOnly = true`, no cache write there). An object with
  a `booking_id` is skipped only when that booking has the same host as the asking event type; a
  booking of another host in the same calendar blocks.
- A booking is written sealed into the target calendar on create/approve/reschedule (same UID as the
  mail ICS, so a team member's client does not show it twice when the mail ICS was accepted too),
  removed on cancellation and erasure. Calendar errors never fail a booking.
- Known race, accepted: a calendar PUT does not take the host lock, so an event created in the very
  second of a booking is not seen by that booking.

## 15. Replacing the token feeds

- 1.4.0: option `legacy_feeds` (script: off on a new installation, on for an existing one, never
  overwritten). Off → the feed endpoint answers 404 and the Feeds view explains the replacement. On →
  unchanged, with a deprecation notice in the Feeds view and the system check (E).
- 1.5.x (optional, E): migration helper «create a calendar per host and copy the future bookings».
- 2.0.0 (`feat(appointments)!:` with `BREAKING CHANGE:`): remove `FeedController`, `FeedService`,
  `FeedCalendar`, `FeedToken`, the Feeds view, the feed rate-limit bucket, `12-feed.php`; migration
  `2.0.0.sql` drops `feed_token` and its unique indexes from event types and hosts (the hosts table
  stays as the booking lock target). Preflight of 2.0.0 warns when `legacy_feeds` was still on.
- Not affected: the signed reschedule, cancel and erase links in invitee mails. They are required by
  the deletion path without an account (`privacy-and-deletion.md`) and grant no calendar access.

## 16. Clients and refresh

| Client | Address | Refresh setting to document |
|---|---|---|
| Outlook (LTSC 2024) + CalDav Synchronizer | profile «Generic CalDAV/CardDAV», DAV URL `https://<site>/dav/`, «Discover resources and assign to Outlook folders» | sync mode two-way, interval 5 minutes, «Use WebDAV collection sync» on |
| iOS calendar | Other → CalDAV account, server `<site>` (well-known) | Settings → Calendar → Accounts → Fetch New Data → every 15 minutes |
| DAVx⁵ | «Login with URL and user name», `https://<site>/` | calendar sync interval 15 minutes (Android minimum) |
| Thunderbird | New calendar → on the network, `https://<site>/dav/` | calendar properties → refresh every 5 minutes |
| eM Client | account → CalDAV, `https://<site>/dav/` | account → synchronisation every 5 minutes |

Not supported, and documented as such: the new Outlook for Windows and the Outlook apps on phones (no
CalDAV). macOS Calendar works with the same settings as iOS but is not part of the tested set.

Every client must pass: discovery, initial sync, create/change/delete in both directions, a booking
appearing by itself within the interval, a revoked grant disappearing, and the clean-up.

## 17. Tests

- PHPUnit (`tests/Unit/Team/`): every pure class; crypto vectors and negative cases (wrong key, wrong
  associated data, tampered byte, wrong length); row MAC tamper detection; lock-out delays; log chain
  (insert, alter, delete, prune) with a fake clock.
- Integration (`21-teamcalendars.php` with one part file per package, J5 and J6 lanes): default
  deny for every role and every method; grant matrix; device lifecycle; clean-up states; booking
  write and busy blocking; log chain; schema pinned (column sets).
- CalDAV clients in the CI (G): Python `caldav` and `vdirsyncer` in a container against the J6 lane
  over HTTPS (self-signed certificate in the test image): discovery via well-known, sync-token
  incremental sync, ETag preconditions, free/busy redaction, clean-up deletes the local copy.
- Manual (G), Hyper-V test VM, only when nobody works on the machine: Outlook LTSC 2024 with CalDav
  Synchronizer, plus iOS/macOS, DAVx⁵, Thunderbird, eM Client; per client the checklist of
  section 16. Never against a production site.

## 18. File ownership during the parallel build

| Package | Owns |
|---|---|
| A CalDAV | `plugins/system/appointmentsdav/**`, `administrator/…/lib/**`, `Service/Team/CalendarObjectStore.php`, `Helper/Team/VendorAutoloader.php`, `THIRD-PARTY-NOTICES.txt`, `tests/tools/sabre/**`, `tests/scripts/team-a-dav.php`, `tests/Unit/Team/Dav*` |
| B Keys | `Service/Team/KeyService.php`, `Service/Team/KeySession.php`, `Helper/Team/CipherSuite.php`, `plugins/user/appointments/**`, `tests/scripts/team-b-keys.php`, `tests/Unit/Team/{CipherSuite,Key}*` |
| C Grants and admin | `Service/Team/{CalendarService,GrantService,InvitationService}.php`, `Table/{Calendar,Grant,Invitation}Table.php`, admin views/controllers/models/forms/layouts `calendars`, `calendar`, `invitations`, `config.xml`, `DisplayController.php`, dashboard layout, `tests/Unit/ConfigurationTest.php`, `tests/scripts/team-c-grants.php` |
| D Devices and site | `Service/Team/{DeviceService,TeamMailer}.php`, `Table/DeviceTable.php`, every site file of the internet calendars (`mycalendars`, `invitation`, site controllers), `Helper/Team/AppPassword.php`, `tests/scripts/team-d-devices.php` |
| E Booking | `Service/Team/{TimingIndex,BookingCalendarBridge}.php`, `Service/{AvailabilityService,BookingStore,FeedService}.php`, `Controller/FeedsController.php`, site `FeedController.php`, eventtype form/model/table, Feeds view, `tests/scripts/team-e-booking.php`, `12-feed.php` |
| F Log and clean-up | `Service/Team/{AuditService,LockoutService,CleanupService}.php`, admin `devices`, `teamlog`, System view/model, `plugins/privacy/appointments/**`, `plugins/task/appointments/**`, `tests/scripts/team-f-log.php` |
| G Tests and docs | `tests/caldav/**`, `tests/manual/teamcalendars*.md`, `tests/Dockerfile*` (TLS), `.github/workflows/appointments.yml`, `README.md` section «Internet calendars» |

Assigned later by the coordinator: E also owns `Field/TeamcalendarField.php` and the one call of the bridge in
`ConferenceService::provision()`; D also owns `Helper/DefaultTemplates.php` (`TEAM_KEYS`, `all()`),
`Table/TemplateTable.php`, `forms/template.xml`, `tmpl/templates/default.php`, `tests/scripts/09-mail.php`,
the `mycalendars` view registration in the site `Router.php` and `media/com_appointments/{css,js}/mycalendars.*`.
The internet calendar mails are DB templates (`#__appointments_templates`, eventtype_id 0, no ICS)
with the purposes `team_device_added`, `team_invitation`, `team_invitation_accepted`,
`team_grant_changed`, `team_access_suspended`. Round 2 (04.11.2026): A also owns `deleteEndedBefore()` and the DAV base address setting; C turns
invitations into account activation with the revoke page; D owns the site view `activation` (instead
of `invitation`); E owns `TimingIndex::emails()` and, as its second part, the booking rows at rest (5.10:
the booking record class and every reader of those columns); F owns `guardLogin()`, the Scheduler
routine «calendar retention», the privacy requests of 9.1 and the system check of 8.3. The
`onAfterRoute` handler of the system plugin is the coordinator's.

Round 4: B owns `EpochAnchor` (5.11); C raises `grant_epoch`, D raises `device_epoch` and implements
`calendarDeleted()`; E owns `lockForBooking()` and `removeOrphans()`, F calls `removeOrphans()` from the
Scheduler. Every parameter that carries a password or secret is marked `#[\SensitiveParameter]` in the
interface and in the implementation.

Integration tests that need another package's
service use `TeamSuite::isStub()` and `TeamSuite::pending()`; `TEAM_NO_PENDING=1` turns every
pending test into a failure before the pull request to `main`.

Frozen for everybody (changes only through the coordinator): `Helper/Team/*` except the files named
above, `Service/Team/*Interface.php`, `Service/Team/TeamServices.php`, the SQL files, `script.php`,
`appointments.xml`, `access.xml`, `KeyProvider.php`, `21-teamcalendars.php`, `test.env`. Language
files: each package adds keys only inside its own `[team-x]` block.

## 19. Open questions for the maintainer

Decided on 04.11.2026: encryption at rest stays (no end-to-end), booking rows encrypted with a server
key (5.10), privacy requests through com_privacy without any blind index (9.1), per-calendar retention,
accounts only by administrators with an activation link and no confirmation step, one recovery key
per site in two printouts, the new Outlook is not supported, runs on any Joomla host (8.3).

Open: none.
