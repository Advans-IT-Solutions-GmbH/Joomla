# Manual acceptance test plan for `com_appointments`

This reusable plan covers **human functional and acceptance testing of the entire extension**:
positive and negative cases, boundaries, permissions, failure handling and integration with real
calendar clients.

**Initial scope reference:** [PR #212](https://github.com/Advans-IT-Solutions-GmbH/Joomla/pull/212),
implementation commit `0cd14f506879403bd8d1c03ac16d61260f554274`. This is a reference for the
plan's original scope, not an assertion about the version currently under test. Record the actual
tested commit, package and environment in every execution report.

**Execution status:** this document records no completed manual tests. Automated checks do not
replace human acceptance. Installation, database inspection and controlled fault injection require
a technically experienced tester.

The plan contains **228 identified test cases**, plus the end-to-end acceptance scenario in
section 18. Use the [blank report template](report-template.md) for each execution.

## Publishing and storage

`tests/manual/` follows the repository's per-extension test layout. The shared packager excludes
`tests`, so this plan and its blank report template are not installed with the extension.

A generic plan with synthetic fixtures and reproducible expectations belongs in the public
repository. **Completed reports, raw mail, screenshots, database dumps and other evidence belong
in access-controlled storage**, not in this directory or public issues. Public bug reports must
be redacted and contain no real identities, deployment details, credentials, feed tokens, signed
management links or JWTs. A blank template is not permission to publish its completed contents.

## 1. Test rules and recording results

### 1.1 Test installations only

- Never use a production installation.
- Use only synthetic people and controlled test mailboxes.
- Deliver mail to a controlled mail server or mail catcher.
- Run privacy and uninstall tests only with disposable test data.
- Treat feed URLs, signed management links and JWTs as passwords. Never include them in public
  bug reports or screenshots.
- Create a restorable snapshot before update, deletion and uninstall tests.
- Tests requiring another system time must run in a **dedicated test VM or isolated container**.
  Never change the clock of a shared computer or server.

### 1.2 Test types

The source plan's category codes are retained alongside all test IDs.

| Code | Meaning |
|---|---|
| POS | Valid input or permitted operation |
| NEG | Invalid input or prohibited operation |
| GRZ | Boundary or special time case |
| KON | Concurrent operations |
| FEH | Deliberately induced, controlled technical fault |
| INT | Integration with Joomla, a mail server or a calendar client |

### 1.3 Result for each test case

Record the following for every case:

| Field | Entry |
|---|---|
| Test case | For example, `BU-01` |
| Build | Actual tested commit and installation package |
| Environment | Joomla, PHP, database, browser and calendar client where applicable |
| Initial state | Users, configuration and records used |
| Actual result | Observations, not just "works" |
| Result | **PASS**, **FAIL**, **BLOCKED** or **NOT RUN** |
| Evidence | Screenshot, redacted HTTP excerpt, mail or database inspection, stored privately |
| Defect reference | For FAIL, an unambiguous link to the defect report |
| Execution | Tester and date |

**Important:** a blocked or unexecuted case has not passed. For a NEG case, a technically correct
refusal is the expected outcome.

## 2. Environments and test data

### 2.1 Environment matrix

Not every combination requires a complete repeat of the test run.

| Environment | Scope |
|---|---|
| Latest Joomla 5.4.x with supported PHP and MySQL | Complete functional run |
| Latest Joomla 6.x with supported PHP and MySQL | Complete functional run |
| Joomla 6 with MariaDB | Installation, update, booking collisions, deletion paths, retention and uninstall |
| Chromium-based desktop browser | Main run |
| Firefox | Booking, management links, form validation and downloads |
| Safari/iOS and Chrome/Android | Mobile booking, time zone, form and management links |
| JavaScript disabled | Complete core flow: booking, rescheduling, cancellation and erasure |
| German, English and French | Inspect every view; at least one complete booking and mail flow per language |
| Outlook Desktop and Thunderbird | Invitation, update, cancellation and calendar subscription; record versions |
| Apple Calendar | Also test if this client is intended to be supported or used |

Record any version change during acceptance. After a code fix, rerun at least the affected case
and adjacent regression cases.

### 2.2 Users

| Identifier | Role |
|---|---|
| `ADMIN` | Joomla Super User |
| `HOST-A` | First host |
| `HOST-B` | Second host |
| `EDITOR-A` | May edit event type A; explicitly denied access to event type B |
| `STATE-A` | May edit A but may not change state |
| `CREATE` | May create schedules but may not publish them |
| `READONLY` | May read permitted administration views but may not change anything |
| `GUEST` | Unauthenticated site visitor |

First **configure and verify permissions in Joomla**. Distinguish inherited permissions from
explicit denials.

### 2.3 Baseline configuration

**Schedule `S-A`:**

- Time zone: `Europe/Zurich`.
- Monday: 10:00-12:00.
- Tuesday: 14:00-16:00.
- Thursday: 14:00-16:00.
- All other days closed.

**Event type `E-A`:**

- Host: `HOST-A`.
- Schedule: `S-A`.
- Duration: 20 minutes.
- Slot grid: 30 minutes.
- Initial buffers: 0 minutes.
- Initial minimum notice: 0 minutes.
- Booking horizon: 30 days.
- Initial daily and weekly limits: unlimited.
- Immediate confirmation.
- Location: host calls invitee.
- Public access and published.

**Other event types:**

| Identifier | Purpose |
|---|---|
| `E-B` | Same host as A, but a different schedule with overlapping availability |
| `E-C` | Host B; available at the same time as A |
| `E-P` | Like A, but requires host approval |
| `E-V` | Video conference |
| `E-X` | Unpublished |
| `E-R` | Registered visitors only |
| `E-L` | Restricted to a particular content language |

Choose a test day sufficiently far in the future for each test. **Do not reuse an already occupied
baseline fixture** when a test requires an empty calendar.

## 3. Installation, update and removal

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IN-01 | POS | Install the ZIP built from the tested commit through Joomla's extension installer. | No PHP/SQL error; component appears in the menu; empty dashboard is usable. |
| IN-02 | POS | Open extension management and plugin management. | Component and bundled task/privacy plugins exist; initial installation enables the intended plugins. |
| IN-03 | POS | Inspect installed administrator, site and plugin files. | Required files and each `LICENSE.txt` exist; no development dependencies in the distributed package. |
| IN-04 | POS | Create a schedule, event type, field, custom mail template and booking. Reinstall the same package over it. | Existing data, custom templates and settings remain; no duplicate default templates. |
| IN-05 | POS | Disable the task and privacy plugins, then update. | Update does not unexpectedly re-enable deliberately disabled plugins. |
| IN-06 | INT | Upgrade a test installation at the post-PR #206 baseline with valid existing schedules to the tested version. | Migration succeeds; existing data and assignments remain correct; new functions are available. |
| IN-07 | NEG | Prepare an orphan schedule reference in a separate legacy test dataset, then start the update. | Explicit, understandable refusal; no automatic deletion or arbitrary reassignment. |
| IN-08 | NEG | Attempt installation on a separate, explicitly unsupported Joomla version. | Understandable refusal instead of a partially installed component. |
| IN-09 | POS | At the end, uninstall on a disposable copy. | Component/plugin registrations, extension tables and associated Scheduler tasks are removed; other extensions are unchanged. |
| IN-10 | POS | Reinstall after uninstall. | Clean start without previous bookings, tasks, tokens or conflicts. |

**Technical inspection:** verify the actual tables and foreign keys on both MySQL and MariaDB.
An installation success message alone is insufficient.

## 4. Schedules and blocked periods

### 4.1 Schedules

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| ZE-01 | POS | Create all of `S-A` through administration, save, close and reopen. | Weekdays, intervals, owner and time zone are stored unchanged. |
| ZE-02 | POS | Enter 09:00-11:00 and 14:00-16:00 for one day. | Both windows are available; the lunch break is not bookable. |
| ZE-03 | POS | Add a date-specific 15:00-17:00 opening on an otherwise open weekday. | Only the override applies; that day's weekly windows are replaced entirely. |
| ZE-04 | POS | Close an otherwise open day completely. Save it again unchanged. | No slots; time fields remain empty when reopened; save succeeds. |
| ZE-05 | POS | Later remove an override window. | The ordinary weekly rule applies again. |
| ZE-06 | GRZ | Create a 22:00-02:00 interval. | Valid slots before and after midnight; no artificial day cutoff. |
| ZE-07 | GRZ | Create a previous-evening window and a next-day override with different grid phases: previous evening 22:10-02:10, next day 00:00-03:00, 30-minute grid. | The next day's override owns its start times; no extra 00:10, 00:40, etc. starts from the previous evening's grid. |
| ZE-08 | NEG | Save an invalid date, invalid time or contradictory closed/open rules. | Understandable validation; no corrupted rules; submitted values retained for correction. |
| ZE-09 | POS | Create a UTC schedule, then save it unchanged as a user in another time zone. | UTC remains UTC; no unnoticed conversion. |
| ZE-10 | NEG | Delete an assigned schedule. | Deletion refused; associated event type remains intact. |
| ZE-11 | POS | Delete a schedule that is no longer used. | Schedule and its rules removed; other schedules unchanged. |
| ZE-12 | NEG | As `CREATE`, attempt to create a published schedule; as `STATE-A`, edit an existing schedule's content. | Creation stays unpublished without publishing permission; content changes do not alter existing publication state. |

### 4.2 Blocked periods

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SP-01 | POS | Add a HOST-A block from 10:30-11:00 on an open day. | Affected slots in A and B are not bookable; C for HOST-B is unaffected. |
| SP-02 | GRZ | Choose a meeting whose buffer alone intersects the block. | Meeting is not bookable. |
| SP-03 | POS | Extend, shorten and then delete the block. | Slot availability follows each change correctly. |
| SP-04 | NEG | Enter an end before/equal to start, an invalid date or an impossible time. | Refusal without data changes; original input remains visible for correction. |
| SP-05 | INT | Enter a block as a user in another Joomla time zone, reopen it and inspect UTC storage. | Displayed local time stays correct; storage represents the same UTC instant. |
| SP-06 | NEG | Inspect the public slot response and booking page while a block exists. | Internal block title and notes are not published. |

## 5. Event types and custom form fields

### 5.1 Event types

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| EV-01 | POS | Create an event type with every setting, close and reopen it. | All values persist and take effect on the site. |
| EV-02 | POS | Change title, alias, description, colour and ordering. | Correct public presentation/order; no broken links or characters. |
| EV-03 | NEG | Save an alias already in use. | Understandable refusal; no overwrite of the other event type. |
| EV-04 | NEG | Submit zero/negative duration or grid, an invalid colour or a missing schedule. | Invalid values rejected server-side. |
| EV-05 | POS | Test every location type: host calls, invitee calls, in person, free text and conference. | Appropriate details in booking page, confirmation, mail and calendar; no remnants of the previous location type. |
| EV-06 | POS | Change publication/access level. Request list and direct URL as a guest. | Visibility enforced consistently through both routes. |
| EV-07 | POS | Assign a content language and switch language. | Visible only in the permitted language; direct URL cannot bypass it. |
| EV-08 | NEG | Change the host of an event type with retained bookings, or delete that event type. | Operation refused; existing bookings remain assigned correctly. |
| EV-09 | POS | Delete an unused event type. | Only that event type and its intended dependent data are removed; no orphan permission assets. |
| EV-10 | POS | Create list and single-event menu items. Open them with and without Joomla SEF. | Both routes work; navigation, forms and management links remain valid. |

### 5.2 Field types

Perform these four steps for every field type:

1. Create, publish and order the field.
2. Book with a valid value.
3. Submit an empty required field or an invalid value.
4. Inspect booking detail, mail presentation and privacy export.

| ID | Field type | Valid test value | Negative/boundary case | Expected result |
|---|---|---|---|---|
| FF-01 | Single-line text | `Test project ÄÖÜ` | Empty required field; very long input | Valid text preserved; empty required value refused; excess length handled predictably. |
| FF-02 | Multiline text | Multiple lines with `&`, commas and accented characters | HTML/script text | Meaningful line breaks retained; no unauthorised HTML or script execution. |
| FF-03 | Number | A valid number | Letters; configured boundary violations where applicable | Server-side validation matches field definition. |
| FF-04 | Telephone | Country calling code and valid national number | Missing number; letters instead of a number | Country selector works; unusable required number refused. |
| FF-05 | Email | Controlled test mailbox | `invalid@` | Valid address stored; invalid address refused. |
| FF-06 | URL | `https://example.org/test` | `javascript:` or an invalid URL | Only permitted URL accepted; no executable link. |
| FF-07 | Single selection | One configured option | Unconfigured option in a manipulated POST | Only configured choices accepted. |
| FF-08 | Multiple selection | Two configured options | Unconfigured option or empty required selection | Valid selection stored completely; invalid selection refused. |
| FF-09 | Checkbox | Checked | Unchecked required field | Required consent enforced. |
| FF-10 | Date | Valid date | For example, 31 February | No silent normalisation to a different date. |

Additional field administration:

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| FF-11 | POS | Reorder fields; disable a field; leave an optional field empty. | Correct order; disabled field not required; empty optional field does not prevent booking. |
| FF-12 | NEG | As a restricted editor, edit/delete a field of a denied event type. | Access refused, including direct POST. |
| FF-13 | POS | Change a field label after a booking and later remove another field definition. | Existing answers remain readable in a controlled way; no broken detail views or exports. |

## 6. Public booking and usability

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| BU-01 | POS | Open E-A, choose a free day/slot and submit valid data and consent. | Exactly one booking; correct confirmation; backend entry; slot then occupied. |
| BU-02 | POS | Repeat the whole flow with JavaScript disabled. | Month, day, slot, time zone, form and confirmation usable without JavaScript. |
| BU-03 | POS | Use Tab, Shift+Tab, Enter and Space to operate the form. | Visible focus, sensible order, clearly labelled fields and selectable slots. |
| BU-04 | POS | With a screen reader, inspect at least date, slot, required fields and errors. | Understandable control names; errors discoverable and associated with the field. |
| BU-05 | POS | Book on a narrow smartphone screen and at 200% zoom. | No clipped required actions, unusable tables or overlapping controls. |
| BU-06 | NEG | Omit name, email or consent. Bypass browser validation and resubmit. | Server-side refusal too; no booking or notification; previously valid input retained. |
| BU-07 | NEG | Submit a form older than one hour. | Understandable refusal or required reload; no booking. |
| BU-08 | NEG | Fill the honeypot using developer tools, or submit in less than two seconds. | Refusal without booking; no external CAPTCHA request. |
| BU-09 | POS | Enter ten distinct valid guests; also test duplicate addresses. | Permitted guests processed; duplicates do not create repeated identical recipients. |
| BU-10 | NEG | Enter more than ten guests, then an invalid guest address. | Understandable refusal; no partially created booking. |
| BU-11 | NEG | Submit an unavailable slot, a different event type or a past time in POST. | Server-side recheck refuses the operation. |
| BU-12 | NEG | Open the confirmation URL in another browser without the original session. | No other person's booking data visible; confirmation page is not a public record lookup. |
| BU-13 | GRZ | After success, reload, navigate back/forward and resend the same POST. | No second active booking for the same slot; no false success. |
| BU-14 | POS | Return to the event type; open a fully booked or closed period. | Understandable empty state and useful navigation instead of an error page. |

## 7. Slot calculation, time zones and boundaries

Disable unnecessary limits, blocks and buffers for these cases.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SL-01 | POS | Window 10:00-12:00, duration 20, grid 30. | Exactly **10:00, 10:30, 11:00, 11:30**. No 11:45 or 12:00. |
| SL-02 | GRZ | Same window with 10-minute before/after buffers. | Exactly **10:30, 11:00, 11:30**; 10:00 excluded by its before buffer. |
| SL-03 | GRZ | Increase both buffers to 20 minutes. | Exactly **10:30 and 11:00**; 11:30 excluded by its after buffer. |
| SL-04 | GRZ | A: 10:00-10:20 meeting, 10-minute after buffer. B: 10-minute before buffer, start 10:30. | 10:30 may be offered if all other rules allow it; buffers are not incorrectly added into a 20-minute separation. |
| SL-05 | GRZ | Window starts at 10:10, grid 30; minimum notice or a block clips its beginning. | Remaining starts stay at 10:10, 10:40, 11:10, etc.; no new grid anchored to the clipped boundary. |
| SL-06 | GRZ | With isolated controlled time, check a slot exactly at minimum notice and one just before it. | Boundary slot allowed; earlier slot refused. |
| SL-07 | GRZ | Horizon 30 days; inspect the last permitted day and following day. | The 30th calendar day is usable through its end; the 31st is not. Schedule time zone governs. |
| SL-08 | GRZ | Configure a fixed UTC range; check start/end boundaries and adjacent slots. | Start inclusive, end exclusive; no starts outside the range. |
| SL-09 | POS | Set daily limit 2 and create two valid bookings. | No further booking of that event type on the same local day. |
| SL-10 | GRZ | Reach the weekly limit; inspect Sunday and following Monday. | Limit applies through Sunday; new week begins Monday in the schedule zone. |
| SL-11 | POS | Create a pending booking with daily/weekly limits, then cancel it. | Pending counts; cancellation releases capacity. |
| SL-12 | POS | Display and book the same real time in Zurich, UTC and New York. | Different displays, same UTC instant; zone switching cannot create a double booking. |
| SL-13 | GRZ | Choose a zone where the same meeting falls on the previous/next calendar day. | Date, slot, confirmation and mail consistently show the correct local day. |
| SL-14 | GRZ | Select a date through month navigation and directly. | Identical slot list; independent of requested range. |
| SL-15 | NEG | Submit an invalid/manipulated time zone. | Controlled refusal or explicitly displayed valid selection; no silent booking shift. |

### Daylight-saving changes

Run these on an isolated environment with an appropriate horizon or controlled clock.
The dates below are reproducible fixtures, not a requirement to change a shared system clock.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| TZ-01 | GRZ | Zurich, **28 March 2027**, window within 02:00-03:00. | No slots in the nonexistent hour. |
| TZ-02 | GRZ | On the same date, inspect a wider window spanning the transition. | Correct real meeting duration; no artificial one-hour shift. |
| TZ-03 | GRZ | Zurich, **31 October 2027**, repeated 02:00-03:00 hour. | No duplicate local starts; the defined first occurrence is used consistently. |
| TZ-04 | NEG | Save a block at a nonexistent local time in the spring transition. | Refusal; original input string displayed unchanged for correction. |
| TZ-05 | NEG | Save a block at an ambiguous local time in the autumn transition. | Refusal instead of silently choosing one occurrence. |
| TZ-06 | GRZ | With isolated historical fixtures, inspect `Pacific/Apia`, 30 December 2011, including an overnight window. | The fully skipped day creates no availability and is not shifted into the following day. |

**Technical supplement:** extreme database years 1000 and 9999 and midnight transitions also
belong in technical regression. An ordinary present-day calendar run cannot reliably cover them.

## 8. Concurrent bookings and approval

**Preparation:** use two separate browser profiles or two testers. Both initially open the same
free state. Manual simultaneous submission is useful practical evidence, but does not alone prove
complete race freedom; the existing technical parallel tests are also required.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| KO-01 | KON | Submit two completed forms for the same slot as simultaneously as possible. | Exactly one active booking; other visitor receives an understandable "just taken" message and refreshed slots. |
| KO-02 | KON | Book E-A and E-B simultaneously: same host, different schedules, overlapping meeting intervals. | Only one succeeds; different schedules do not bypass the host conflict. |
| KO-03 | KON | Simultaneously submit nonoverlapping meetings whose buffers violate the required separation. | Only a permitted combination is saved. |
| KO-04 | KON | Book the same time for HOST-A and HOST-B simultaneously. | Both independent hosts can be booked. |
| KO-05 | KON | Leave one daily/weekly capacity unit and simultaneously book two different slots. | Limit is not exceeded. |
| KO-06 | KON | Leave a form open; administrator then adds a block; visitor submits. | New block rechecked at submission; no stale booking. |
| KO-07 | POS | Book E-P. | Pending state; slot immediately occupied; not presented as confirmed. |
| KO-08 | POS | As an authorised host, approve the pending booking. | Same record confirmed; calendar sequence increased; correct notifications. |
| KO-09 | POS | Reject/cancel another pending booking. | Slot released; correct cancellation. |
| KO-10 | NEG | Without state permission, approve/cancel, including direct POST. | No change and no mail. |
| KO-11 | KON | Simultaneously reschedule a booking and cancel it through another route. | Consistent final state; no second record or corrupted occupancy. |
| KO-12 | KON | Simultaneously delete a schedule and assign it to an event type. | Either valid assignment or refused action; never an event type referencing a nonexistent schedule. |

## 9. Mail, templates and real calendar clients

### 9.1 Notification matrix

Trigger every event below. Inspect content and **raw message source** for each recipient.

| ID | Event | Expected main properties |
|---|---|---|
| MA-01 | Immediately confirmed booking | Invitee, permitted guests and host receive appropriate messages; calendar `REQUEST`, `CONFIRMED`, initial sequence 0. |
| MA-02 | Booking requiring approval | Pending message; calendar `REQUEST`, `TENTATIVE`; slot remains held. |
| MA-03 | Approval | Confirmation with same UID and increased sequence. |
| MA-04 | Reschedule | New time, same UID, increased sequence; no second independent invitation. |
| MA-05 | Cancellation | Same UID, increased sequence, `METHOD:CANCEL`, `STATUS:CANCELLED`. |
| MA-06 | Reminder | Correct meeting/time zone; calendar attachments; no unintended booking change. |
| MA-07 | Successful conference retry | Updated connection, same UID, increased sequence. |
| MA-08 | Erasure of a future booking | Only the intended host cancellation; no former contact data, answers or erasure reasons. |

For **every message actually sent**, check:

- Expected recipients, no unrelated addresses.
- `text/calendar` with the appropriate `method`.
- The additional intended `application/ics` attachment.
- Correct start/end, duration, location, UID, sequence and status.
- No feed credentials.
- No internal diagnostics or conference secrets.
- No management capabilities for unauthorised recipients.

**Capability requirement:** only the primary invitee receives signed reschedule, cancellation and
erasure links. Guests and the host do not thereby receive the invitee's management permissions.
Check documentation and actual delivery for consistency during acceptance; the component README's
earlier contradictory recipient sentence has been corrected to match this rule.

### 9.2 Templates and delivery

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| MA-09 | POS | Book once each in German, English and French; test a host profile with another language/zone too. | Recipient-specific text/time; no raw language keys. |
| MA-10 | POS | Create an event-specific template, then disable it. | Active specific template takes precedence; intended site-wide fallback afterwards. |
| MA-11 | NEG | Disable matching language-specific templates. | Documented English fallback; no empty/unintelligible mail. |
| MA-12 | POS | Use names, locations and answers containing umlauts, accents, emoji, commas, semicolons, backslashes and newlines. | Readable mail and valid calendar; no truncated UTF-8 characters. |
| MA-13 | POS | Use a custom template without link/conference placeholders. | Mandatory information and authorised management links still present; attachments retained. |
| MA-14 | NEG | Submit a newline in input used in the subject. | No injected mail headers or additional recipients. |
| MA-15 | FEH | Deliberately make SMTP unreachable before booking. | Booking remains saved; explicit error; no false promise of successful delivery. |

### 9.3 Calendar-client acceptance

For each selected client, run the same sequence with a new test booking.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| KA-01 | INT | Open and accept the invitation from the actually received mail. | One meeting with correct local time, duration, location and participants. |
| KA-02 | INT | Reschedule through the extension; process the update in the client. | Existing meeting updated; no duplicate. |
| KA-03 | INT | Reopen an older invitation afterwards. | Older sequence does not overwrite the newer change. |
| KA-04 | INT | Cancel and process the cancellation in the client. | Existing meeting removed or marked cancelled according to client behaviour. |
| KA-05 | INT | Open a calendar attachment containing long UTF-8 text. | No import error; complete, readable description. |
| KA-06 | INT | Export backend ICS and import it. | Same essential meeting data as the backend; no feed credentials. |

Document client differences. Distinguish client behaviour from defects in generated ICS.

## 10. Rescheduling, cancellation and signed links

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| LI-01 | POS | Open reschedule link in a private browser without login, choose a permitted slot and confirm. | No login required; exactly the same booking record at its new time. |
| LI-02 | POS | Compare database/backend and ICS after rescheduling. | Booking ID and UID unchanged; sequence increased; old slot free, new slot occupied. |
| LI-03 | NEG | Reschedule onto a slot taken in the meantime. | Understandable refusal; original meeting retained. |
| LI-04 | POS | Reschedule a pending meeting. | Still pending; not silently approved. |
| LI-05 | POS | Open cancellation link without confirming, then confirm. | GET alone changes nothing; only valid confirmation cancels and releases the slot. |
| LI-06 | GRZ | Test cancellation reason empty, 2,000 characters and 2,001 characters. | Empty/permitted length accepted; excess length refused predictably. |
| LI-07 | NEG | Individually change booking ID, action, revision or signature in a valid link. | No access or state change. |
| LI-08 | NEG | Reuse a cancellation link as an erase/reschedule link. | Refused; signature is action-specific. |
| LI-09 | NEG | Open an old link after a successful change. | No further change using the stale capability. |
| LI-10 | POS | Repeat a successfully confirmed action in the same browser. | Understandable completion state; no repeated mail, bookings or audit entries. |
| LI-11 | NEG | Submit confirmation without a Joomla CSRF token or with a wrong token. | No change. |
| LI-12 | POS | Reschedule and cancel with JavaScript disabled. | Fully usable; same rules as with JavaScript. |

## 11. Privacy and erasure

### 11.1 Self-service

Create a booking with identifiable synthetic markers in **every personal field**: name, email,
phone, time zone, language, guests, answers, free text and conference data.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| DS-01 | POS | Open erase link without login. | Understandable explanation of the affected meeting, removal and retained anonymous record or full deletion. |
| DS-02 | NEG | Only open the page; do not confirm. | No erasure yet. |
| DS-03 | POS | Confirm in anonymisation mode. | All personal fields cleared; intended anonymous meeting metadata retained; anonymisation timestamp set. |
| DS-04 | POS | Repeat for a future meeting. | Meeting cancelled and slot free; host message contains no former invitee data or erasure reason. |
| DS-05 | POS | Switch to full deletion and repeat with a new booking. | Booking row removed; only minimal deletion evidence retained. |
| DS-06 | NEG | Reuse a consumed erase link or substitute another booking ID. | No disclosure or second erasure action. |
| DS-07 | INT | Inspect backend, CSV, ICS, feed and privacy export after erasure. | Previously stored contact, guest, answer and conference data no longer available. |
| DS-08 | INT | Inspect audit table. | Only booking ID, action, path, timestamp and permitted administrator ID; no invitee data. |

**Important:** the component cannot retroactively erase delivered mail, exported files, external
calendar copies or backups. Treat these separately from removal in the active extension database.

### 11.2 Joomla privacy workflow

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| DS-09 | INT | Request, confirm and process a Joomla export for an address with several bookings. | All of that address's bookings and readable answers exported; no other bookings. |
| DS-10 | NEG | Request export as an additional guest address. | No disclosure of primary invitee or other guests' personal information. |
| DS-11 | NEG | Test addresses differing only in accents; test letter case separately. | Accent similarity does not establish ownership; case follows documented normalisation. |
| DS-12 | INT | Process a confirmed Joomla removal request. | Same erasure scope as self-service; audit path `privacy_request`. |
| DS-13 | NEG | Configure a legal blocking reason and repeat formal removal. | Removal explicitly blocked, reason visible; no silent partial processing. |
| DS-14 | POS | Remove the reason and retry. | Intended erasure possible. |
| DS-15 | POS | While formal removal is blocked, separately test self-service and retention. | Behaviour follows the documented separate rules; blocking is not unintentionally extended. |
| DS-16 | POS | Erase as an authorised administrator, then try as an editor without erasure permission. | Only authorised operation succeeds; administrator ID recorded only for administrative audit path. |

## 12. Calendar feeds and credentials

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| FE-01 | POS | As an authorised administrator, open event and host feeds. | Event feed contains only its bookings; host feed contains associated event types. |
| FE-02 | INT | Subscribe to both feeds in a real calendar client. | Readable calendar name/meetings; correct time, status and location. |
| FE-03 | POS | Create, reschedule and cancel; refresh feed after each action. | Changes retain UID; cancellation appears as the intended tombstone. |
| FE-04 | NEG | Remove token, change one character or use an unknown calendar ID. | Generic 404 without meeting data or distinction between sensitive states. |
| FE-05 | POS | Rotate event token. | Old event URL immediately invalid; new URL valid; host feed unchanged. |
| FE-06 | POS | Rotate host token. | Old host feed invalid; event feeds unchanged. |
| FE-07 | NEG | Save an event form opened before token rotation. | Old token is not restored. |
| FE-08 | INT | Inspect HTTP response, then request with unchanged `If-None-Match`. | Calendar content type, private/no-store, noindex/nofollow; unchanged response is 304 without calendar body. |
| FE-09 | POS | Change a booking and resend previous ETag. | Updated content and changed ETag instead of false 304. |
| FE-10 | GRZ | Use prepared fixtures to test history/cancellation boundaries. | Documented 90-day history and 30-day cancellation period; no indefinitely growing output. |
| FE-11 | NEG | As an editor lacking access to all a host's event types, request/rotate the combined host feed. | No access to the combined credential. |
| FE-12 | INT | Delete a subscribed calendar entry in the client. | Joomla booking remains; subscription is read-only. |
| FE-13 | INT | Inspect an anonymised booking's feed entry; search public pages/mail for feed URLs. | No former personal data in the anonymous entry; feed credential not distributed publicly or through invitations. |

**Acceptance note:** the requested 15-minute refresh is not a client guarantee. Record client
refresh delays separately.

## 13. Scheduler, reminders and retention

### 13.1 Reminders

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| AU-01 | POS | Create the reminder and retention tasks in Joomla and run them manually. | Routines available; execution/result traceable; system checks updated. |
| AU-02 | POS | Set reminder offsets such as `10,5` and prepare a suitably timed confirmed meeting. | Due reminders delivered to intended recipients, each with calendar attachments. |
| AU-03 | POS | Run the routine again immediately. | Successfully processed offset not normally sent again. |
| AU-04 | GRZ | Delay execution while the meeting is still in the future. | Due unsent reminders caught up according to documented rules. |
| AU-05 | NEG | Inspect pending, cancelled, anonymised and already-started meetings. | No ineligible reminders. |
| AU-06 | POS | Reschedule after a reminder has been sent. | Marker appropriately reset for the new meeting; later reminder refers to new time. |
| AU-07 | FEH | Disable SMTP for a due reminder, run task, re-enable SMTP and retry. | Task reports failure; failed offset remains retryable. Partial deliveries are not represented as guaranteed exactly-once delivery. |
| AU-08 | INT | Run through the actual CLI/cron route, also with Joomla in a subdirectory where applicable. | Mail links point to the correct public site, not CLI paths or wrong hosts. |
| AU-09 | NEG | Remove public site URL and, where applicable, `live_site` for the CLI test. | Configuration problem explicitly reported; no mail containing broken management links. |
| AU-10 | POS | Configure an empty reminder list. | Reminders disabled; retention remains independent. |

### 13.2 Retention

For older meetings, use isolated fixtures or let a short meeting finish. Do not bypass UI rules
by trying to create prohibited past bookings.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| AU-11 | GRZ | Prepare meetings just before, at and after the documented retention cutoff. | Only eligible meetings processed; reproducible, documented boundary. |
| AU-12 | POS | Test global retention, an empty inherited event value and an event override. | Correct inheritance/override. |
| AU-13 | GRZ | Set event retention to 0 for a just-ended meeting. | Eligible on the next appropriate run; future meetings protected. |
| AU-14 | POS | Test anonymisation and full deletion separately. | Same complete erasure scope as the other paths. |
| AU-15 | POS | Prepare more due records than batch size and run repeatedly. | Bounded processing per run; backlog fully cleared over several runs; no duplicate audit actions. |
| AU-16 | KON | Reschedule a due candidate into the future during retention execution. | Recheck prevents erasure of the now-future meeting. |
| AU-17 | POS | Prepare expired audit entries and rate-limit windows. | Separate audit retention respected; rate-limit metadata cleaned after its intended period. |
| AU-18 | NEG | Disable task or cause failure; inspect system checks. | Missing/failed execution visible; no claim that retention is nevertheless guaranteed. |

## 14. Video conferencing

The three bundled providers generate URLs without external provider APIs.
**A generated URL alone is not a successful human connection test.**

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| VI-01 | POS | Configure no conference; make an ordinary booking. | No provider contact or accidental conference data. |
| VI-02 | INT | Configure a controlled Jitsi test instance and book E-V. | Consistent link on confirmation page, authorised mail, ICS and backend. |
| VI-03 | INT | Join through the generated link using two test devices. | Both enter the intended room; audio/video work according to the test instance. |
| VI-04 | POS | Create two bookings and compare room names. | Different hard-to-guess rooms; no names, email addresses or readable booking numbers. |
| VI-05 | INT | Configure Jitsi JWT; compare guest and host messages. | Host receives moderator capability; guests, feed and ordinary editors do not. |
| VI-06 | GRZ | Try joining before, within and after the configured JWT window. | Token times match configuration and provider enforcement. |
| VI-07 | POS | Reschedule. | UID retained; time-bound links/tokens updated for new time. |
| VI-08 | POS | Configure a generic HTTPS pattern with `{room}` and book. | Placeholder correctly replaced; no unexpected API call. |
| VI-09 | POS | Configure a fixed room, book twice and add an event-specific override. | Reuse is explicit; override affects only that event type; collision warning present. |
| VI-10 | NEG | Configure invalid URL scheme, missing `{room}`, invalid Jitsi pattern or insufficient entropy. | Understandable validation or explicit provider error; no unsafe link. |
| VI-11 | FEH | Use a deliberately failing provider configuration on an isolated environment. | Booking remains saved; failure visible; diagnosis contains no secret, token or complete confidential URL. |
| VI-12 | POS | Correct configuration and retry conference creation. | Valid link; failure cleared; same UID, increased sequence, update mail. |
| VI-13 | POS | Cancel or anonymise the meeting. | Stored conference data removed; intended teardown invoked. |
| VI-14 | NEG | Retry as an unauthorised user. | No link change, mail or disclosure of another host's moderator information. |

Providers without an actual teardown API cannot necessarily close an already-open room on the
server. Do not confuse that documented limitation with successful removal of stored conference data.

## 15. Administration, exports and permissions

### 15.1 Administration functions

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| AD-01 | POS | Open dashboard empty, then with today's, future, pending and cancelled bookings. | Correct empty states/counts; no unauthorised event types included. |
| AD-02 | POS | Filter booking list individually and jointly by event type, state, date range and name/email. | Filters combine; pending state `0` is not treated as "no filter". |
| AD-03 | GRZ | Inspect bookings at both UTC date boundaries. | Correct documented inclusive date range. |
| AD-04 | POS | Test sorting, pagination, filter reset and special-character searches. | No SQL errors, lost filters or wrong records. |
| AD-05 | POS | Open detail containing all field types and a conference error. | Readable answers and safe diagnosis; no raw internal structures or secrets. |
| AD-06 | POS | Reschedule through administration to a permitted UTC time. | Same conflict rules as public flow; same ID/UID; UTC input clearly identified. |
| AD-07 | NEG | Administratively reschedule to occupied/invalid time. | Existing meeting unchanged; understandable error. |
| AD-08 | POS | Export filtered list as CSV. | Exactly the authorised filtered dataset; correct columns, UTF-8, line breaks and quoting. |
| AD-09 | NEG | Export synthetic text `=1+1`, `+1+1`, `-1+1` and `@TEST`; open in a spreadsheet. | Protected as text; no unintended formula execution. |
| AD-10 | NEG | Request CSV without valid POST/CSRF token or appropriate rights. | No export. |
| AD-11 | POS | Open system checks with complete and incomplete configuration. | Platform, mail transport, plugins, both tasks, last execution, feed state and oldest audit are understandable. |

### 15.2 ACL and held-edit protection

Test every visible action through a **direct URL/POST request** too. A hidden button alone is
not access control.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| RE-01 | NEG | As a guest, request administration pages, CSV, ICS and system checks. | No access to internal data. |
| RE-02 | POS/NEG | As EDITOR-A, edit A, then directly request B. | A permitted; B refused, including titles, fields, templates and selectors. |
| RE-03 | NEG | As READONLY, save, change state, delete, export or rotate a token. | Only explicitly permitted read operations possible. |
| RE-04 | NEG | As STATE-A, approve, cancel or change publication state. | No state change despite edit permission. |
| RE-05 | NEG | Open site-wide template or system checks without options permission. | Access refused. |
| RE-06 | KON | User 1 opens a record for editing; user 2 attempts to edit/save. | Joomla checkout respected; no unnoticed loss of user 1's changes. |
| RE-07 | NEG | Open edit view directly; submit Save/Cancel without the normally held edit ID. | No bypass of edit checks. |
| RE-08 | POS | Open a record normally, then save unchanged or cancel. | Success; checkout released afterwards. |
| RE-09 | NEG | Edit/export/delete a mixed selection of permitted and denied event types. | No action on unauthorised records and no disclosure of their data. |

## 16. Input, request and privacy robustness

These cases exercise the tester's own isolated installation. They do not replace a separate,
complete security audit.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SI-01 | NEG | Use `<`, `>`, `&`, `'` and `"` in titles, names, answers and searches. | Correct output; no broken page, unauthorised HTML or SQL error. |
| SI-02 | NEG | Remove CSRF token from every writing action. | No state change for booking, approval, rescheduling, cancellation, erasure, rotation or export. |
| SI-03 | NEG | Substitute other records' IDs in booking actions and backend forms. | No unauthorised records readable/changeable. |
| SI-04 | NEG | Repeatedly request public booking/feed endpoints in an isolated environment until rate limiting applies. | Controlled limiting; no 500 errors or unbounded processing. |
| SI-05 | NEG | Vary artificial `X-Forwarded-For` headers during that test. | Untrusted headers do not bypass documented limiting. |
| SI-06 | INT | Inspect booking/rate-limit tables and component logs. | No raw visitor IP in component data; only intended short-lived hashed rate-limit values. |
| SI-07 | FEH | Trigger one mail, provider and validation error; inspect visible errors and logs. | No secret-bearing stack trace, invitee data in component errors or confidential tokens. |
| SI-08 | INT | Inspect feed requests in web-server/proxy logs. | Infrastructure does not log complete feed credentials; otherwise configure logging before acceptance. |

Server/proxy logs are an installation responsibility. The component cannot control their contents
on its own.

## 17. Languages, presentation and self-hosting

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| UI-01 | POS | Open every backend/site view in German, English and French, including error/empty states. | No missing translations or visible `COM_APPOINTMENTS_...` keys. |
| UI-02 | POS | Inspect German text. | Swiss High German; no `ß`; understandable, consistent terminology. |
| UI-03 | POS | Inspect long French labels and long event titles on a smartphone. | No obscured fields or clipped actions. |
| UI-04 | POS | Switch language between form and next page; inspect mail/meeting. | No data loss, wrong time or unusable links. |
| UI-05 | INT | Inspect browser network activity through a complete booking. | Component assets local; no external fonts, CDNs, trackers or CAPTCHA services. |
| UI-06 | INT | Block outbound Internet access **only for the isolated test environment**; keep local mail catcher reachable; run core flow. | Booking, rescheduling, cancellation, erasure and ICS generation work without a third-party scheduling service. |
| UI-07 | INT | Generate Jitsi/generic room link without opening the conference; inspect network. | URL generation requires no external API call; actual conference join is a separate step. |
| UI-08 | POS | Run Joomla in a subdirectory and with SEF/language prefixes. | Correct URLs for assets, forms, mail links and downloads. |

## 18. Complete functional acceptance scenario

Configure this scenario **only through Joomla administration**, without file/database edits.

### Setup

1. Create a schedule with an IANA time zone.
2. Create three weekly windows, two in the afternoon.
3. Configure an event type:
   - 20-minute duration;
   - 30-minute grid;
   - rolling 30-day horizon;
   - host calls invitee.
4. Configure the form:
   - required name;
   - required email;
   - optional additional guests;
   - required phone with country selector;
   - two required single-line fields;
   - one optional multiline field;
   - mandatory privacy consent.
5. Create a public menu item.
6. Configure host address, mail transport, privacy notice and Scheduler.

### Procedure

| Step | Action | Expected result |
|---|---|---|
| 1 | Open event type on a smartphone and change display time zone. | Appropriate days and correct local slots. |
| 2 | First submit with a required answer missing. | Understandable error; no booking; other input retained. |
| 3 | Correct and book. | Exactly one booking and correct confirmation. |
| 4 | Inspect invitee, guest and host mail. | Correct recipients, language, phone details and calendar attachments; capabilities separated. |
| 5 | Accept invitation in a desktop calendar. | One meeting with correct time/duration. |
| 6 | Reschedule through signed link. | Same UID; existing calendar entry updated; old slot free. |
| 7 | Subscribe to and refresh calendar feed. | Current meeting visible; no duplicate UID. |
| 8 | Trigger reminder. | Correct reminder with ICS and valid links for the authorised recipient. |
| 9 | Erase own data through erase link. | Future meeting cancelled; personal data removed; slot free; minimal audit entry. |
| 10 | Book again, then export/remove through Joomla privacy. | Formal privacy workflow fully functional. |

Then repeat the core flow **with JavaScript disabled**.

## 19. Defect assessment and acceptance criteria

### Defect classes

| Priority | Examples | Consequence |
|---|---|---|
| **P0 - critical** | Another person's data visible; unauthorised change/deletion; exposed secrets; personal data still accessible after erasure | No acceptance |
| **P1 - high** | Double booking; wrong meeting time; lost booking; broken approval; calendar update failure; missing essential erasure path | No acceptance |
| **P2 - medium** | Limited functional defect with safe workaround; incorrect filtering; important missing translation | Fix before acceptance or explicitly document acceptance of the limitation |
| **P3 - low** | Cosmetic/minor wording/layout issue without functional loss | Document and assess |

### Minimum conditions for human acceptance

- All core flows pass on Joomla 5.4 and Joomla 6.
- MySQL/MariaDB-relevant cases pass.
- No open P0/P1 defects.
- All three erasure paths actually exercised.
- Concurrent bookings and cross-event host conflicts exercised.
- Invitations, reschedules and cancellations exercised in real calendar clients.
- JavaScript-free booking and management flow passes.
- All three languages inspected.
- No unresolved blocked mandatory cases.
- Actual tested commit recorded unambiguously.
- After required code fixes, affected cases and regression pass again, and CI is green on the new
  commit.

**Human acceptance and the required green Copilot recommendation are separate gates.**
A human approval does not automatically turn "Needs a closer look" into "Approval recommended".
Record each decision and the commit it covers separately; neither is asserted by this plan.

## 20. Final execution report

Copy the [blank report template](report-template.md) into private, access-controlled storage.
Record actual observations and case-level evidence using section 1.3. Leave unexecuted cases as
**NOT RUN**; do not infer manual passes from CI results.
