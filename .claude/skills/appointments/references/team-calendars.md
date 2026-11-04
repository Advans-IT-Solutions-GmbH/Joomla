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
  add-in (highest priority), Apple Calendar, DAVx⁵, Thunderbird, eM Client. `/.well-known/caldav`
  works.
- **Roles per calendar:** free/busy, read, write (view, create, change and delete), manage.
  Managers share with the team; only administrators share with externals. External grants have no expiry. An invitation link for an
  external user is valid once, for 72 hours.
- **Log:** sign-ins and changes only (no read access), 12 months, hash chain, checksum visible in
  the administrator only.
- **Failed sign-ins:** temporary lock after 10 failures with a growing delay, never permanent.
- **Blocking a user:** clean-up mode. The device sees the calendar as deleted on its next
  synchronisation and removes its local copy; after that it is blocked completely. Tested per client.
- **Encryption B:** calendar contents are encrypted on the server so that only authorised users can
  decrypt them. A recovery key is kept offline by the owner. After a password reset a manager grants
  again, or the recovery key is used.
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

Not protected, stated plainly:

- **A compromised running server** (attacker executes PHP or reads memory): while a key holder's
  request runs, the server holds the calendar key in memory. Encryption B is encryption at rest, not
  end-to-end encryption; CalDAV clients cannot do end-to-end encryption.
- **Database and files together** (a full hosting backup): the times of all objects become readable
  (server key), contents stay encrypted.
- **Devices that never connect again** keep their local copy. The clean-up only reaches devices that
  synchronise. This is why the usage rule requires a device lock code.
- **Booking data outside the calendar**: see section 5.9.

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
password, every time skeleton, every MAC. Recovery then requires the recovery key (which does not
depend on the site secret) and new devices. The README says so; the system check warns.

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
  `user-key/password` (user id, key version), `user-key/device` (device id, user id, key version),
  `calendar-box` (calendar id, version).
- Wraps of CK: `CipherSuite::seal(publicKey, CK)`; on open, the plaintext must be exactly 32 bytes.
- Object (scheme 1): `encrypt(CK_v, ics, associatedData('object', calendarId, uri, v))`.
- Object (scheme 2, booking flow): `seal(boxPublicKey_v, envelope)` where the envelope is
  `"ACv1\n" . json_encode(['c' => calendarId, 'u' => uri]) . "\n" . ics`; on open the calendar id
  and URI must match the row. Sealed objects are re-encrypted to scheme 1 by the next key holder's
  request (`CalendarObjectStoreInterface::reencrypt()`), without changing ETag or sync token.
- Time skeleton: `encrypt(key(TEAM_TIMING), skeleton, associatedData('timing', calendarId, uri))`.
- UID hash: `hash_hmac('sha256', calendarId . "\0" . uid, key(TEAM_UID), true)`.
- Row MAC: `hash_hmac('sha256', associatedData('row', table, field1, value1, …), key(TEAM_ROW_MAC), true)`
  over the security-relevant columns, listed per table in the owning service's docblock.

### 5.3 Where keys exist

- A **CalDAV request** of an active device: app password → device key → user secret key → wraps →
  calendar keyring. Everything lives in `SecretValue` objects for the request only.
- A **web session**: at password sign-in the password unlocks the user key (`KeySessionInterface::onLogin`).
  The key is kept in the Joomla session encrypted with a random 32-byte session key; the session key
  is in a separate cookie (`HttpOnly`, `Secure`, `SameSite=Strict`, session lifetime, separate names
  for site and administrator). The key is released only after the session passed the second factor.
  A sign-in without password (passkey, remember-me) leaves it locked: actions that need it answer 423
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

Created once by a user with `core.admin` (backend, unlocked session): X25519 pair, the secret shown
once as Base32 groups with a fingerprint, to print and keep offline; only the public key is stored.
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
RECURRENCE-ID, STATUS, TRANSP, X-MICROSOFT-CDO-BUSYSTATUS and the referenced VTIMEZONE. Not the
UID. The busy pattern within the booking horizon is public anyway (the booking page shows free
times); history and the time beyond the horizon are the additional exposure.

### 5.9 Conflicts of encryption B with other requirements (reported, not resolved silently)

1. **Bookings table.** `#__appointments_bookings` keeps invitee name, e-mail, phone and answers in
   clear, as today: reminders (CLI), signed reschedule/cancel/erase links (no account), the privacy
   workflow and retention all need them without a key. The calendar copy of a booking is encrypted,
   the booking row is not. Same category as the booking mails to the team, which the maintainer
   already accepted. **Open question 1.**
2. **Privacy requests of invitees.** The privacy plugin cannot search encrypted calendar contents.
   Objects written by the booking flow are linked by `booking_id` and removed with the booking.
   Personal data that team members type into their own events is not findable by the operator;
   only whole calendars or time ranges can be deleted (calendar `retention_days`). **Open question 2.**
3. **Sign-in without password** (passkeys) cannot unlock keys; such users need a password sign-in
   for key operations (adding a device, granting). Normal CalDAV use is unaffected.
4. **Free/busy and the booking page** need server-readable times: section 5.8.

## 6. Authentication

- CalDAV: HTTPS only (plain HTTP → 403, no body, no redirect), HTTP Basic, user name = Joomla user
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
  MFA records (`#__user_mfa`, any method except backup codes). Grants are effective only with it.
  «Add device» requires it at that moment, in a session that passed it. **Every CalDAV request** of an
  active device whose account has no active second factor is refused with 403 (logged
  `dav.auth_failure`, reason `no_mfa`) before any calendar is touched; devices in clean-up are not
  affected, because they receive no data.
- **Lock-out** (section 6 of `LockoutServiceInterface`): CalDAV and website sign-ins of calendar
  users; website lock-out via `onUserAuthorisation` (before the session starts). Counter buckets are
  keyed hashes; no user name or address in clear.

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
- Invitation (section `InvitationServiceInterface`): a user with `appointments.team.external` invites an address with calendar
  and role; the mail holds the only copy of the 32-byte token; 72 hours, once. Acceptance creates the
  account in the external group only (or accepts for an existing signed-in account with that
  address); the user must set up a second factor; then an administrator **confirms** (state
  `GRANT_PENDING_CONFIRMATION` → `GRANT_PENDING_KEY`). The confirmation step is the defence against
  an intercepted invitation mail. **Open question 3.**
- No sharing over CalDAV (no `share`/`invite` POST, no `MKCALENDAR`, no `ACL`). PROPPATCH changes only
  per-user display properties (colour, order), stored in `client_props`; anything else 403.

### 7.1 Groups, access level and administrators

| Item | Created by the installer | Meaning |
|---|---|---|
| Group «Internet calendars internal» (de-DE «Internetkalender intern») | below the registration group (`com_users` `new_usertype`, normally «Registered»), no permission of its own | may receive team grants; second factor enforced |
| Group «Internet calendars external» (de-DE «Internetkalender extern») | same | may receive grants from administrators only, at most write; second factor enforced |
| Access level «Internet calendars» | holds both groups | access of the «My calendars» menu item, so the item is shown to these accounts only |
| Component permission `appointments.team.external` «Share with external users» | in `access.xml`, granted to nobody by default (Super Users have it implicitly) | invite external users, confirm their acceptance, grant to them |

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

### 8.1 Hosting (Apache, shared hosting such as cyon)

To verify on the dev installation before anything else (curl, in this order): `OPTIONS`, `PROPFIND`
(Depth 0 and 1), `REPORT`, `PUT`, `DELETE`, `MKCALENDAR` (must reach PHP and get 403 from sabre, not
405 from Apache), `Authorization` header present in PHP, `/.well-known/caldav` reaching Joomla. Known
risks: a web application firewall or ModSecurity rule set of the host that blocks WebDAV methods or
XML bodies; a host rule that serves `/.well-known/` itself; a security extension in Joomla (WAF) that
blocks unknown methods. Clients can always be configured with the full `/dav/` address, so a
non-working well-known address degrades discovery only. No change to the operator's `.htaccess` is
required if it is Joomla's `htaccess.txt`.

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
2. **Hidden:** the calendar home lists nothing, so the client removes the calendars.
3. **Revoked:** 401 for every request.

Each phase ends when delivered or after `team_cleanup_days` (option, default 14). Triggers: the
user's «remove device»; an administrator's «block device» or «block calendar access»; Joomla account
blocked; password reset. An account without an active second factor is refused outright (section 6).
Grants of a blocked user are suspended. Client behaviour per client is part of the manual test plan, because clients
differ (Outlook with CalDav Synchronizer in two-way mode must delete the Outlook items, not upload
them again; one-way «Outlook → server» would try to upload, which the refused writes stop).

## 12. Mails (work package D)

`TeamMailerInterface`: new device (always), invitation, invitation accepted (to administrators),
grant changed (to owner and managers), access suspended. Joomla mailer, recipient language, no secret
except the invitation link. Booking mails stay as they are, with content and ICS to the team.

## 13. Administrator views

- Backend menu: submenu «Dashboard» and «Internet calendars» (view `calendars`), in the manifest.
- `calendars` (C): list, edit (title, description, colour, owner, allow externals, blocks
  availability, retention), grants, rotate key, recovery key setup and use. Navigation links on the
  dashboard (C).
- `invitations` (C, `appointments.team.external`), `devices` (F: all devices, «last synchronised», block with clean-up, revoke),
  `teamlog` (F: events, verify, checksum).
- System view (F): libsodium, HTTPS, Authorization header, well-known reachability, plugins enabled,
  MFA enforcement, recovery key present, site secret fingerprint changed.
- `DisplayController::EDIT_VIEWS` gets the new edit views (C).

## 14. Booking page and calendars (work package E)

- Event type form: target calendar (calendars the host can write) and further busy calendars.
- `AvailabilityService` adds `BookingCalendarBridgeInterface::busyFor()` to the host's busy periods,
  also inside the locked booking transaction. Objects with a `booking_id` are skipped.
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
| Apple Calendar (iOS) | Other → CalDAV account, server `<site>` (well-known) | Settings → Calendar → Accounts → Fetch New Data → every 15 minutes |
| Apple Calendar (macOS) | Other CalDAV account, manual, `https://<site>/dav/` | account → refresh every 5 minutes |
| DAVx⁵ | «Login with URL and user name», `https://<site>/` | calendar sync interval 15 minutes (Android minimum) |
| Thunderbird | New calendar → on the network, `https://<site>/dav/` | calendar properties → refresh every 5 minutes |
| eM Client | account → CalDAV, `https://<site>/dav/` | account → synchronisation every 5 minutes |

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

Frozen for everybody (changes only through the coordinator): `Helper/Team/*` except the files named
above, `Service/Team/*Interface.php`, `Service/Team/TeamServices.php`, the SQL files, `script.php`,
`appointments.xml`, `access.xml`, `KeyProvider.php`, `21-teamcalendars.php`, `test.env`. Language
files: each package adds keys only inside its own `[team-x]` block.

## 19. Open questions for the maintainer

1. Booking rows keep invitee data in clear (5.9.1). Accept as today, or encrypt the booking's
   personal fields to the calendar's public key (then the reminders, signed links, privacy export and
   retention need a redesign)?
2. Privacy requests cannot find personal data that team members type into their own events (5.9.2).
   Accept with a README note and per-calendar retention?
3. External invitation: keep the administrator confirmation after acceptance (specified, recommended),
   or activate immediately once the second factor is set?
4. Recovery key: one per site (specified), or one per calendar owner?
