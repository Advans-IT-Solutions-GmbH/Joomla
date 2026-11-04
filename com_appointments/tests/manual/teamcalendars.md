# Manual test plan: internet calendars with real CalDAV clients

This plan covers the **internet calendars** of `com_appointments` (CalDAV, release 1.4.0) with the
calendar clients the component documents: classic Outlook (Office LTSC 2024) with the CalDav
Synchronizer add-in, the iOS calendar, DAVx⁵ on Android, Thunderbird and eM Client. It complements the [main manual plan](README.md), whose rules for publishing, evidence and results
apply here unchanged.

The binding behaviour is in the specification
[`team-calendars.md`](../../../.claude/skills/appointments/references/team-calendars.md)
(sections 6, 7, 11 and 16). Automated protocol checks do not replace this plan, because real
clients differ exactly where the clean-up and the refresh matter.

**Execution status:** this document records no completed manual tests.

## 1. Rules

### 1.1 Where and when

- **Test installations only.** Never a production site, never production accounts.
- Desktop clients for Windows (Outlook, Thunderbird, eM Client) run in a **dedicated Hyper-V test
  VM**. Run the plan only when nobody else is working on the host machine: clients synchronise in
  the background, and the clean-up cases take hours of wall time.
- Mobile clients run on test devices or test profiles that hold no personal accounts.
- Take a VM checkpoint before the run, so the VM can be reset afterwards.

### 1.2 HTTPS and certificates

CalDAV answers over HTTPS only. Use one of:

- A test installation with a publicly trusted certificate (required for iOS and Android unless the
  test CA is installed there).
- The Joomla 6 test stack (`tests/docker-compose.joomla6.yml`) running **inside the test VM**, at
  `https://localhost:8444/`. Its certificate is signed by a test CA built with the image; copy
  `/etc/ssl/appointments-test/ca.pem` out of the container (`docker cp`) and import it into the
  VM's trusted root store (Windows) or the client's own store (Thunderbird). The certificate names
  `localhost` only, so this stack serves clients inside the same VM.

Never switch certificate verification off in a client. A client that offers «accept invalid
certificates» keeps it off; a case that only works with it on is a **FAIL**.

### 1.3 Secrets and reports

- Use synthetic users and controlled test mailboxes only (main plan section 1.1).
- App passwords, recovery keys, the site secret and activation links are passwords. They never appear in a report,
  a screenshot, an issue or a chat. Blur them before taking any screenshot.
- Reports contain **no host names, IP addresses, user names or app passwords**. Write
  `<test-site>` for the address.
- Record results per case with the fields of main plan section 1.3. Use the
  [blank report template](report-template.md) and store the completed report in access-controlled
  storage.
- After the run: remove every test device in «My calendars», remove the accounts from every client,
  delete the test CA from every trust store it was added to, and reset the VM checkpoint.

### 1.4 Test types

The codes of main plan section 1.2 apply (POS, NEG, GRZ, KON, FEH, INT). A case is recorded per
client as `<ID>/<client code>`, for example `IC-16/OL`.

## 2. Environment and fixtures

### 2.1 Clients

Record every version before the run. A version change during the run restarts the affected cases.

| Code | Client | Platform | Record |
|---|---|---|---|
| OL | Outlook (Office LTSC 2024) with CalDav Synchronizer | Windows 11 test VM | Outlook build, add-in version, .NET version |
| TB | Thunderbird | Windows 11 test VM | Thunderbird version |
| EM | eM Client | Windows 11 test VM | eM Client version, licence type |
| IO | iOS calendar | iOS or iPadOS test device | iOS version |
| DX | DAVx⁵ with the device's calendar app | Android test device | DAVx⁵ version, calendar app and version |

Not in the set: the new Outlook for Windows and the Outlook apps on phones are **not supported**
(they speak no CalDAV); the macOS calendar works with the iOS settings but is not tested here.

Install CalDav Synchronizer only from the project's official release page, and record the version
and the checksum of the downloaded installer.

### 2.2 Users

Every user is a synthetic Joomla account that `ADMIN` created in the user management and activated
through the activation link (Joomla's registration stays off), with a second factor (TOTP) set up at
the first sign-in and a password sign-in.

| Identifier | Group | Grants |
|---|---|---|
| `ADMIN` | Super User | none on the calendars (sees lists and the log only) |
| `OWNER` | Internet calendars internal | owner and manager of `CAL-T`, `CAL-R`, `CAL-X` |
| `WRITER` | Internet calendars internal | write on `CAL-T` and `CAL-R` |
| `READER` | Internet calendars internal | read on `CAL-T` |
| `BUSY` | Internet calendars internal | free/busy on `CAL-T` |
| `NOGRANT` | Internet calendars internal | none |
| `EXT` | Internet calendars external | write on `CAL-X` |

### 2.3 Calendars and booking

| Identifier | Settings | Content before the run |
|---|---|---|
| `CAL-T` | team calendar | `EV-1` single event with title, location and description; `EV-2` all-day event; `EV-3` weekly series with one moved and one deleted occurrence; `EV-4` event created in `America/New_York` |
| `CAL-R` | second team calendar, used for the revocation case | `EV-5` single event |
| `CAL-X` | allows externals | `EV-6` single event |
| `CAL-F` | no grant for anybody but `OWNER` | `EV-7` single event |

- Event type `E-CAL`: target calendar `CAL-T`, host `OWNER`, otherwise as `E-A` of the main plan.
- Recovery key created and stored offline for the duration of the run.
- Option «Days per clean-up phase» at its default, unless a case says otherwise.

### 2.4 Devices and the observer

Each tested user adds **one device per client** in «My calendars» → «Add device», with the client
selected and the usage rule confirmed. The app password is copied straight into the client and
nowhere else.

The **observer** is a second, already tested client of `OWNER` (for example Thunderbird in the VM
once `TB` has passed `IC-01` to `IC-08`). It is used to create changes on the server and to check
what the client under test sent. Record which client served as observer.

## 3. Client set-up

Addresses use `<test-site>` for the test installation. The refresh intervals are those of the
specification, section 16.

| Client | Account | Refresh setting |
|---|---|---|
| OL | CalDav Synchronizer profile «Generic CalDAV/CardDAV», DAV URL `https://<test-site>/dav/`, then «Discover resources and assign to Outlook folders» | sync mode two-way, interval 5 minutes, «Use WebDAV collection sync» on |
| IO | Settings → Calendar → Accounts → Add Account → Other → CalDAV account; server `<test-site>` only (discovery through `/.well-known/caldav`) | Fetch New Data → every 15 minutes |
| DX | «Login with URL and user name», `https://<test-site>/` | calendar sync interval 15 minutes (the Android minimum); battery optimisation off for DAVx⁵ |
| TB | New Calendar → On the Network, location `https://<test-site>/dav/`, «Find calendars» | calendar properties → refresh every 5 minutes |
| EM | Add account → CalDAV, `https://<test-site>/dav/` | account → synchronisation every 5 minutes |

Menu paths differ between client versions. Record the actual path when it differs; that is not a
defect.

**Outlook specifics.** Map every discovered calendar to a **new, empty** Outlook calendar folder,
never to the default calendar that may hold other items. Keep the add-in's option to accept invalid
certificates off. Turn on the add-in's logging for the run, so `OL-05` can read which requests were
sent.

## 4. Common checklist (every client)

Run every case for every client of section 2.1, with the device of the user named in the
procedure. «Within the interval» means within the client's configured refresh interval plus one
minute; measure and record the actual time.

**Before each client**, restore the initial state: the calendars and events of section 2.3 as listed
there (`IC-12` changes the series, `IC-26` removes a grant), the grants of section 2.2 re-granted and
active again (`IC-30` suspends them), and **fresh devices** for every user of that client (the
clean-up cases revoke the old ones). The observer keeps its own device and stays untouched.

### 4.1 Account, discovery and sign-in

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IC-01 | POS | Set up the client for `WRITER` as in section 3. | Exactly `CAL-T` and `CAL-R` appear, with title and colour; no other calendar, no principal or address book of another user. |
| IC-02 | NEG | Set up the client for `WRITER` with the Joomla password instead of the app password. | Sign-in refused; the client reports an authentication error; nothing is synchronised. |
| IC-03 | NEG | Change one character of the app password. | Same refusal as `IC-02`; the client asks for the password again or reports an error. |
| IC-04 | NEG | Set up the client with the plain HTTP address of the same installation (where the client allows it): `http://<test-site>/dav/`, for the test stack `http://localhost:8081/dav/`, because port 8444 speaks TLS only. | Refused; no redirect to HTTPS is followed into a sign-in; no data. |
| IC-05 | POS | Open «My calendars» as `WRITER` after the first synchronisation; as `ADMIN`, open the backend devices view. | The device shows the client, state active and «last synchronised» from the first sync. |
| IC-06 | POS | Check the mailbox of `WRITER` after adding the device. | One mail about the new device, without the app password. |

### 4.2 Synchronisation in both directions

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IC-07 | POS | Compare the initial sync of `CAL-T` with the fixtures. | `EV-1` to `EV-4` complete: local times correct (`EV-4` converted from New York), all-day event on the right day, series with its moved and deleted occurrence. |
| IC-08 | POS | Create an event in the client in `CAL-T`. | The observer shows it within its own interval with the same title, time and description. |
| IC-09 | POS | Change title and time of that event in the client. | The observer shows the change; no duplicate on either side. |
| IC-10 | POS | Delete that event in the client. | The observer no longer shows it. |
| IC-11 | POS | With the observer, create, change and delete one event each in `CAL-T`. | Each change reaches the client by itself within the interval, without a manual refresh. |
| IC-12 | POS | Edit one occurrence of `EV-3` in the client (move it by one hour). | The observer shows the moved occurrence; the rest of the series is unchanged. |
| IC-13 | GRZ | Create an event in the client across the next daylight-saving change in `Europe/Zurich`. | Start and end stay at the entered local times on both sides. |
| IC-14 | GRZ | Create an event with a long UTF-8 description (accents, umlauts, emoji, 2000 characters). | Stored and shown completely on the observer. |
| IC-15 | KON | Change `EV-1` in the client and, before the client syncs, differently on the observer. | One consistent version on the server; the client resolves or reports the conflict according to its setting; no duplicate. Record the outcome. |
| IC-16 | FEH | Take the client offline, change an event, bring it back online. | The change reaches the server at the next sync; nothing else changes. |

### 4.3 Roles and default deny

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IC-17 | POS | Set up the client for `READER`; try to create, change and delete an event in `CAL-T`. | The client shows `CAL-T` read-only or reports an error for every write; the observer shows no change; the client does not keep a phantom item after the next sync. |
| IC-18 | POS | Set up the client for `BUSY`. | `CAL-T` shows only busy blocks with the text «Busy» in the user's language; no title, location, description or attendees of any event; writes refused as in `IC-17`. |
| IC-19 | NEG | As `WRITER`, add `CAL-F` by its full address (in clients that accept a calendar address). | Not found; nothing of `EV-7` is shown. |
| IC-20 | NEG | As `NOGRANT`, open «My calendars» and try to add a device. | Refused: no device without a grant; no app password shown. |
| IC-21 | POS | Set up the client for `EXT`. | Only `CAL-X` appears; read and write work as for `WRITER`. |

### 4.4 Booking page

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IC-22 | INT | As a guest, book `E-CAL` on the public page. | The booking appears by itself in `CAL-T` in the client of `WRITER` within the interval. |
| IC-23 | INT | Accept the invitation mail of that booking in the same client (where the client imports mail invitations into the same calendar). | One event, not two: the mail ICS and the calendar object share the UID. Record the client behaviour. |
| IC-24 | INT | Create an event in `CAL-T` in the client during an open slot of `E-CAL`. | After the client synchronised, the slot is no longer offered on the booking page. |
| IC-25 | INT | Cancel the booking from `IC-22` through the extension. | The event disappears from the client within the interval. |

### 4.5 Revocation and clean-up

The clean-up states are «empty» (the calendars are served without objects), «hidden» (the calendar
home lists nothing) and «revoked» (every request refused). Each phase ends when the device has
fetched it, or after the configured days. Note the time of each phase in the backend devices view.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IC-26 | POS | As `OWNER`, revoke the grant of `WRITER` on `CAL-R`. | `CAL-R` disappears from the client of `WRITER` within the interval, or the client reports it as gone; its local events are removed; `CAL-T` is unaffected. Record what the client shows. |
| IC-27 | POS | As `WRITER`, «Remove device» in «My calendars». Wait for the client to sync. | Phase empty: every local event of every calendar of this device is deleted in the client. The observer still shows every event: **nothing was deleted on the server and nothing was uploaded again**. |
| IC-28 | POS | Continue `IC-27` until the phase hidden is delivered. | The calendars are removed from the client, or the client reports them as gone. Record which. |
| IC-29 | POS | Continue `IC-28` until the device is revoked. | Every request is refused; the client reports an authentication error. No event reappears. |
| IC-30 | POS | Repeat `IC-27` to `IC-29` with a new device, triggered by `ADMIN` blocking the Joomla account of `WRITER`. | Same phases; the grants of `WRITER` are suspended and the access-suspended mail is sent. Unblock and re-grant afterwards. |
| IC-31 | NEG | Add a separate device for `WRITER` in the client and let it sync once. Take the client offline, «Remove device» for it, create an event in the client, bring it back online. | The backend devices view shows the device in phase empty when the client syncs; the write is refused by the server (403), not by a revoked sign-in; the observer shows nothing; the client reports the error or discards the item. |
| IC-32 | POS | After `IC-29`, add a new device for `WRITER` and set up the client again. | Works with the new app password; the old app password stays refused. |

## 5. Outlook with CalDav Synchronizer

Additional cases for the client with the highest priority. Run them after section 4 for `OL`.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| OL-01 | POS | Record the Outlook build and the add-in version; check that the installer came from the official release page. | Versions and installer checksum recorded. |
| OL-02 | POS | Create the profile «Generic CalDAV/CardDAV» with DAV URL `https://<test-site>/dav/` and «Test or discover settings». | The connection test succeeds and lists the calendars of `IC-01`; «Discover resources and assign to Outlook folders» maps each one to a new folder. |
| OL-03 | POS | Set sync mode two-way, interval 5 minutes, «Use WebDAV collection sync» on; record the conflict resolution and the time range settings. | Settings stored; the first scheduled sync runs after at most 5 minutes. |
| OL-04 | NEG | Without the test CA in the trusted root store (test stack only), run «Test or discover settings». | Refused with a certificate error; no data. With the CA imported, it works. |
| OL-05 | POS | Read the add-in log after two scheduled syncs without changes, then after one change on the observer. | The syncs use `sync-collection` with a sync token; an unchanged calendar transfers no events; one change transfers that event only. |
| OL-06 | KON | Conflict case: change the same event in Outlook and on the observer within one interval, with conflict resolution «Automatic»; repeat with «Outlook wins» and «Server wins». | Each outcome matches the setting; no duplicate; the server holds one version. |
| OL-07 | INT | Create a meeting with an attendee in an internet calendar folder. | The event reaches the server; the server sends no invitation mail of its own (no scheduling). Record what Outlook sends. |
| OL-08 | POS | Mark an Outlook item private and give it a category. | Record how the add-in maps privacy and categories; the observer shows the same classification. |
| OL-09 | POS | Clean-up (`IC-27`) with the two-way profile: watch the Outlook folder and the add-in log. | The Outlook items are **deleted**, not uploaded again; the log shows no PUT after the clean-up started; the observer still shows every event. |
| OL-10 | NEG | A second profile in mode «Outlook → Server (Replicate)» on a test folder with one event, then start the clean-up of its device. | The upload attempts are refused (403); the server is unchanged; record what the add-in reports. |
| OL-11 | POS | After phase hidden, read the profile's status. | The profile reports the missing calendar; the Outlook folder stays empty. Delete the profile and the folder by hand; record the steps. |

## 6. Server checks with the client set-up

Run once per test installation, with `curl` from the test VM. The integration suites check the
same points in the CI; this confirms them on the installation the clients use.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SV-01 | POS | `curl -i https://<test-site>/.well-known/caldav` | `301` to `/dav/`. |
| SV-02 | NEG | `curl -i http://<test-site>/dav/` | `403` without body and without redirect. |
| SV-03 | NEG | Ten requests with a wrong app password for one user, then one with the correct one. | The request after the tenth failure gets `429` with `Retry-After`, also with the correct password; after the delay the correct password works again. |
| SV-04 | NEG | `MKCALENDAR` and `ACL` on the calendar home with a valid device. | `403`. |
| SV-05 | POS | Response headers of an authenticated `PROPFIND`. | `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow`, no session cookie. |
| SV-06 | POS | In the backend log view, «verify chain» after the whole run. | The chain verifies; the log holds sign-ins, device and grant changes and object changes, no reads and no content. |

## 7. Acceptance

- Every case of section 4 for every client of section 2.1, every case of section 5 and every case
  of section 6 is **PASS**.
- **BLOCKED** or **NOT RUN** blocks acceptance. A missing device or licence is not a pass.
- A behaviour that the specification leaves to the client (`IC-23`, `IC-26`, `IC-28`, `OL-08`,
  `OL-11`) is recorded as observed. It is a **FAIL** only when data is lost on the server, data
  stays visible to a user without a grant, or deleted events come back.
- Record per client the measured refresh time (`IC-11`, `IC-22`) next to the configured interval.
- The README's client instructions are checked against what the clients really show; a deviation
  is a documentation defect.
