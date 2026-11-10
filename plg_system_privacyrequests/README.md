# Core privacy requests without an account

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Pre-release.** A standalone Joomla system plugin for a public removal-request and
email-confirmation page. Requires Joomla 5.4 or 6 and PHP 8.1+. It does not require
Appointments, J2Commerce, a new user account, or changes to Joomla Core files.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, enabled Joomla Privacy component, a configured native
mail transport supporting the visitor's original address, and active privacy plugins
covering the site's data. Appointments and J2Commerce are optional. A usable local
public operator contact route is required; contact is not confirmation or proof of
ownership.

## Installation and activation

Build `plg_system_privacyrequests` with its `build.sh` wrapper and install the ZIP
through Joomla's normal extension installer. The shared
[installation approval policy](../README.md#installation-safety-and-repeatable-system-checks)
applies. Installation does not enable this plugin, change mail transport, or create
Scheduler tasks. An operator must enable **System - Privacy requests** deliberately
after testing the site's privacy plugins, permissions, mail and retention policy.
Set its required **Operator contact route** to an existing publicly accessible local
Joomla page, for example `index.php?option=com_contact&view=contact&id=42` or
`index.php?Itemid=42`, using the actual contact or menu ID. No external URL or contact
service is supplied by default. Check that the linked page genuinely lets an
unauthenticated visitor contact the responsible operator. The link is available
without submitting or validating the request form and appears with delivery-failure
warnings in the authorized Core request view.
The linked contact service may have its own address-validation limits; publish
another genuinely usable communication method there if its form cannot accept
the visitor's original mailbox. A different reply channel is not ownership evidence.

The public page is:

```text
index.php?option=com_ajax&plugin=privacyrequests&group=system&format=html
```

### Distribution and updates

The approved update channel uses this repository's GitHub raw update feed and
version-specific GitHub Release ZIPs, not an external privacy-processing service.
The manifest registers
`https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/plg_system_privacyrequests/updates/update.xml`.
Joomla's updater therefore discloses the fetching server's public IP address and
normal HTTP metadata to GitHub; privacy requests and mailbox contents are not
uploaded through this channel.

`release-system-privacyrequests.yml` prepares a human-reviewed release PR and
generates the version files and update feed. The first feed is created by that
workflow, not hand-written for an unpublished development version; until the first
release is prepared and published, the registered feed is not yet available.
`publish-system-privacyrequests.yml` publishes only the verified merged release
commit, using `system-privacyrequests-v*` tags and the shared publication safeguards.
Defining these workflows does not run them. The pre-release candidate remains a
manual-install development artifact, not a published release or completed
installation/UI acceptance.

### Existing-site three-package upgrade and rollback boundary

The coupled candidate is an **upgrade of an existing Appointments/shop test
installation**, not a requirement to install either product for this adapter.
The installed acceptance sequence is the generic plugin ZIP, the Appointments
component ZIP, then the existing J2Commerce privacy ZIP. Use Joomla's normal
installer and its exact-file approval process, not manual Core/plugin copies.

1. Save a matching files/database/encryption-key backup and record plugin, mail and
   Scheduler states. Pause new adapter intake and administrator removal/completion
   during the change. Keep the generic plugin disabled until its public local
   operator contact route is checked; an upgrade preserves its previous state, so
   disable it deliberately for staging if it was already enabled.
2. Install the three ZIPs in the sequence above. Preserve the Appointments
   anonymise/delete and legal-blocker settings, calendar keys and genuine
   administrator authorization. Preserve the existing shop privacy plugin,
   **Guest Request Confirmation** and bundled consent system plugin states.
3. Check that the recorded mail/Scheduler states remain unchanged and no task was
   activated. This upgrade evidence must not be generalized to a fresh component
   or shop install: those existing installers enable bundled task plugins on
   first install, and Appointments can create its initial Scheduler tasks. Do not
   add these optional products merely to obtain the standalone adapter or start
   their tasks without a separate deliberate setup.
4. On the isolated test installation, verify the configured contact route, captured
   confirmation delivery, the genuinely registered Core flow, and the existing
   administrator-created shop guest confirmation links before enabling public
   adapter intake. Keep mail/Scheduler activation a separate operator decision.
   The adapter adds no task and never enables mail.

No pending request, token or status is migrated. The shop system plugin rewrites
only `com_privacy.notification.admin.export` and `.admin.remove`; the generic
adapter sends `com_privacy.notification.remove` with its own local route. Existing
shop links therefore remain on the existing shop confirmation route, including
already-sent links while that feature remains enabled. Do not disable the shop
privacy callbacks or guest confirmation as a handover step.

Disabling the generic plugin can stop new adapter intake, but also disables its
own additional processing guard. **Do not process or complete adapter-managed
requests while it is disabled.** Keep pending requests, cleanup/outcome provenance
and encrypted keys; uninstalling or deleting these stores is not a rollback.
A full matched backup is a rollback boundary only before new requests or removal
work have occurred. Afterwards, hold processing and reconcile the retained audit
and unfinished work before any recovery; never restore statuses or discard newer
privacy requests to make a rollback appear complete. Re-enable a verified
compatible adapter before resuming the Core workflow.

For addresses supported by native Joomla mail, visitors submit an email address,
then confirm possession using the code delivered to that exact address. Native
Unicode-local-part delivery is unsupported on the tested stock platforms; the
operator contact link remains available when this or another delivery failure
prevents automated confirmation. GET only displays the form or prefills a code; changes require POST
and the real Joomla session form token. There is no return-URL or redirect facility.
Keep an operator contact route available when automated submission or delivery fails.

## Updating

Install the newer standalone ZIP through Joomla's normal installer and shared
approval/backup process. Updates preserve plugin publication state. Recheck the
contact route, mail and active privacy callbacks on an isolated test installation;
an update is not acceptance of native mail delivery or complete site-wide erasure.

## Uninstall

Pause intake and native processing before removal. Uninstall removes this plugin and
drops its three auxiliary tables (`#__privacy_guest_subjects`,
`#__privacy_guest_attempts`, `#__privacy_guest_outcomes`); it leaves native Core
privacy requests and audit records in place. This destroys adapter provenance,
outcome and rate-limit state, not the underlying Core requests or all subject data.
It is not a rollback or a successful removal/completion. Reconcile pending work and
preserve the matching lawful recovery evidence before uninstalling. Disabling also
removes the adapter's processing guard: do not process or complete adapter-managed
requests while it is disabled.

## Native Core lifecycle

Creation and renewal use the public **administrator RequestModel::save** to save an
actual pending `com_privacy` removal request. Guest creation is audited by Core's
ActionlogModel with its stock guest-submission wording and actor zero, not a fictional
administrator. The native MailTemplate sends the confirmation; false results and
exceptions are checked. Receipt persistence precedes delivery: a delivery failure
retains the original pending request ID/date and is visible in the authorized native
request view. The public response does not claim receipt, delivery, confirmation or
erasure, including when admission is refused. Globally disabled mail is reported
uniformly before any address-specific decision.
Administrator private-message content is translated using each recipient's backend
language, otherwise the site default. Native `com_messages` still controls delivery
preferences and its notification mail; no application-wide language is replaced.

**Native mail limitation:** the tested stock Joomla 5.4 and 6 mailers reject Unicode
local parts such as an accented character before `@`, even with an SMTPUTF8-capable
transport. This plugin does not change Core's global address validator, transliterate
the address or fabricate a confirmation token. An accepted pending receipt remains
actionable with its delivery-failure warning. The contact route permits communication
and proportionate identity/scope assessment; it does not confirm the Core request,
substitute another mailbox as ownership proof or supply another token.
**Stock Core has no supported manual confirmation action for this pending request.**
Until confirmation to the original address works, its native automated lifecycle
remains pending and unresolved; do not force confirmed/completed statuses, dispatch
Remove prematurely or claim that contact alone completes processing. Any separately
verified manual handling requires its own lawful process and accurate documentation;
this plugin does not implement a parallel erasure engine.
Exact Unicode address isolation is not evidence that native
Unicode mail delivery or its positive confirmation lifecycle works. Internationalized
domains are a separate case and must not be used to claim local-part support.

Confirmation uses the public **site ConfirmModel::confirmRequest**, a request-local
table/factory and a scoped email principal with ID zero. It does not authenticate a
visitor or change the application's identity. Exact normalized address, request ID,
type, stored token hash and expiry pin selection before Core saves the confirmation.
Tokens are hashed, valid for 24 hours and one-use; renewal replaces the token without
restarting the receipt date. Expiry ends that token, not the person's right to request
removal. Existing unmanaged pending requests are not adopted or renewed, and existing
confirmed requests are not altered.

The genuine administrator still uses Core **Remove**, which checks all active privacy
plugins' CanRemove callbacks and dispatches their Remove callbacks. Core logs a removal
attempt before dispatch; that entry is not proof of successful erasure. **Complete is
a separate native action**, blocked by an unsuccessful, warning-producing or failed
removal and until the administrator reviews scope. A prior incomplete attempt requires
remediation review and another Remove; checking a box is not evidence that a known
component-specific remainder has gone.

On this authorized native request view, the adapter's own review/warning marker
scopes a small-screen layout adjustment: the header can wrap, the untouched
Statistics prompt stacks, and long table values wrap. No Core file, consent state,
content visibility or completion authorization is changed. Other native views and
the public template are not restyled; a dark OS preference does not make stock
Cassiopeia a dark-rendering template.

## Scope and safeguards

Email possession proves access to that mailbox, not every identity or entitlement to
all data classes. Operators must assess account mismatches, additional identity needs,
legal blockers and retained data. Accent-distinct addresses are not interchangeable;
database collation is only a candidate prefilter, never ownership proof.

Atomic admission reserves a maximum of five attempts per normalized address and twenty
per client IP in each 15-minute window, separately for submission and confirmation.
Each bucket reservation is independently transactional, not an all-or-none pair:
the IP is charged first and remains charged when the address is refused or fails.
An IP refusal does not allocate or charge a fresh address bucket. A refused submission
does not create a Core receipt. Session binding is CSRF protection, not a third
session-rate bucket.
Counters hold HMAC identifiers, not raw addresses/IPs; these are pseudonymous, not
anonymous. Old counters are purged on later admissions. Concurrent subject changes and
administrator operations are serialized; confirmation pins and rechecks the native
pending row. Public errors omit addresses, tokens and exception details; operational
failures are logged without those values.

Auxiliary tables are `#__privacy_guest_subjects` (keyed subject-to-Core-request mapping
and delivery failure), `#__privacy_guest_attempts` (window counters) and
`#__privacy_guest_outcomes` (Core request removal outcome). They do not replace Core's
request or audit records. No background retention task is installed for these records:
include their still-linkable identifiers and the native Core records in the site's
retention/access policy. Uninstalling the plugin drops exactly these three tables
and their indexes (see [Uninstall](#uninstall)).

Core success is **not website-wide proof that everything was erased**. An enabled
privacy plugin may implement export only. Audit/retention obligations, unreadable
encrypted calendars and copies in delivered email, calendar clients, backups, external
providers or CRM systems require separate assessment. No export-only plugin gains an
erasure implementation by installing this adapter.

The [Appointments integration](../com_appointments/README.md#formal-privacy-requests)
keeps encrypted, still-linkable operational references for genuinely failed internal
cleanup and independently blocks Complete until the actual cleanup and remaining-scope
warnings are resolved. Existing J2Commerce administrator-created guest confirmation
remains separate; this plugin's own mail uses the non-administrator Core template,
without rewriting legacy confirmation links.

## Frontend capability contract

Discover the enabled adapter with **exactly**:

```text
index.php?option=com_ajax&plugin=privacyrequests&group=system&format=json&task=capabilities
```

Read `data[0]` in the native com_ajax envelope and validate
`capability: "core-privacy-guest-remove"`, `schema: 1`, `enabled: true`,
`requiresAccount: false`, `core: "com_privacy"` and `returnRedirects: false`.
`requestUrl` and `confirmationUrl` are absolute local HTML URLs. Validate both URLs
against the site's origin before linking to `requestUrl`; do not construct alternate
confirmation/return routes. HTTP success alone does not establish this capability.
HTML capability requests are rejected rather than rendering com_ajax's nested-array
placeholder. Request and confirmation responses use no-store/private and no-referrer.

## Installed acceptance

`tests/lifecycle.cjs` and `tests/appointments.cjs` exercise genuine installed Core HTTP
controllers/models with synthetic captured mail and normally installed failure probes.
`tests/registered.cjs` exercises the unchanged registered-user Core request/confirmation
route, its genuine user audit, native user pseudonymisation and separate completion.
`tests/concurrency.cjs` requires two actual PHP processes against the same isolated
database; an unsupported native Unicode-mail positive remains explicitly open and
returns a nonzero exit rather than making partial acceptance green.
`tests/admission.cjs` uses independent HTTP sessions on two actual PHP processes to
exercise both operations' five-address/twenty-client thresholds, charged denials,
no-fake-receipt refusal and the persistent API's exact 900-second clock boundary.
`tests/operator.cjs` exercises the configured native public Contact form, real
captured operator communication and authorized refusal of premature Remove/Complete,
while keeping the failed original Unicode receipt unresolved. A separate ASCII
reply channel in that contact test is communication only, not an address substitution
or positive Unicode-mail confirmation.
`tests/fixture.php` is CLI-only, requires an explicit synthetic fixture root and never
writes confirmed/completed Core statuses. The Appointments fixture uses a real staff
account, native authenticator setup and genuine owner keys; it never creates an account
for the requesting guest. Fixture resets delete only their own explicitly named
synthetic calendars using the native authorized calendar service.

Do not install probes or run fixture controls on a live site. A passing lifecycle lane
does not establish the full browser/locale matrix, legacy-shop coexistence or production
readiness. Build, installed upgrade, negative/concurrent cases and relevant UI acceptance
must be completed before deployment.

See [test setup and limits](tests/README.md) for the isolated native suites. Neither
CLI mail-failure tests nor exact address isolation establishes positive
Unicode-local-part delivery; the stock native mail limitation remains unresolved
unless the original-address confirmation lifecycle actually succeeds.

## Multi-Language Support

Exactly de-DE, en-GB and fr-FR are shipped; de-DE uses Swiss High German.
Subjects/bodies follow the documented native template/recipient-language rules.
Another locale requires a repository-wide maintainer decision.

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
