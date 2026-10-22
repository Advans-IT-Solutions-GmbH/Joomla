# Video conferencing

## The requirement

An operator configures a conferencing server once, in the component settings. From then on, every
booking of an event type whose location type is `conference` gets a meeting link automatically, and
that link appears in the confirmation e-mail, in the attached calendar entry, on the confirmation
page and in the administrator's booking detail.

## The adapter interface

Providers differ enormously in what they allow, so the component defines one narrow interface and
each provider implements as much of it as it can.

```php
interface ConferenceProviderInterface
{
    public function getName(): string;

    /** Form XML fragment describing this provider's settings. */
    public function getConfigForm(): string;

    /** Create or derive the meeting for a booking. */
    public function createMeeting(MeetingRequest $request): MeetingResult;

    /** Release the meeting, if the provider supports it. No-op otherwise. */
    public function destroyMeeting(string $roomIdentifier): void;

    /** True when this provider contacts a remote host. */
    public function requiresOutboundAccess(): bool;
}
```

`MeetingRequest` carries **only** what a provider needs to name a room and place it in time: a room
seed derived from the booking `uid`, the start and end in UTC, the event type title and the
duration. It carries **no invitee name, e-mail or phone number**. A provider that needs more than
that requires an explicit operator opt-in, and the setting states exactly what leaves the server.
See `references/privacy-and-deletion.md`.

`MeetingResult` carries the join URL, the room identifier for later teardown, and an optional
host URL when the provider distinguishes host and guest entry.

Providers are registered in the DI container, so a third party can add one from a plugin without
touching the component.

## Providers in version 1

### 1. Self-hosted Jitsi Meet — the primary path

The primary path, pointed at an operator's own Jitsi installation. It needs no API: a Jitsi room
exists the moment someone opens its URL, which is what makes it the only provider that fits the
self-hosted constraint without an exception.

Settings:

| Setting | Purpose |
|---|---|
| Base URL | e.g. `https://meet.example.com`, no trailing slash |
| Room name pattern | tokens for a prefix, the event type alias and a random part |
| Room name entropy | number of random characters, minimum 16 |
| Authentication | none, or JWT |
| JWT secret, app id, audience, issuer | only when JWT is selected |
| Host token validity | minutes before the start that a host token becomes valid |

Room naming is the security control, because an unauthenticated Jitsi room is open to anyone who
guesses its name. The default pattern must therefore produce a name with at least 16 characters of
entropy derived from the booking `uid` through an HMAC with a `KeyProvider` key — never the plain
`uid`, never a readable sequence, never the invitee's name.

With JWT authentication the component signs a token per participant: a guest token that is valid
from fifteen minutes before the start until the end plus a grace period, and a moderator token for
the host. The secret lives in the component settings and is never written into a URL that is logged.

`requiresOutboundAccess()` returns `false`: the component only builds a URL.
`destroyMeeting()` is a no-op, since Jitsi rooms disappear when empty.

### 2. Generic room-URL provider

The same mechanism without the Jitsi specifics: the operator gives a URL pattern containing a
`{room}` token, and the component fills it. This covers BigBlueButton behind a fixed front end, a
second Jitsi instance, Nextcloud Talk and anything else with a predictable room URL. No API, no
outbound access, no provider-specific code.

### 3. Fixed meeting room

For a service that offers **no programmable interface at all**. The operator pastes one permanent
room link and the component writes that same link into every invitation.

This is the only option available for Proton Meet, which publishes no public API. It is a real
limitation and the component must present it honestly: the settings screen states that this
provider reuses one room for every meeting, that two bookings close together can therefore collide,
and that the operator should not use it for overlapping appointments. Do not disguise this as
per-meeting room creation.

An optional per-event-type override lets the operator set a different fixed room per event type,
which is the usual way to reduce collisions.

## Providers deliberately not in version 1

| Provider | Why |
|---|---|
| Microsoft Teams | Needs Microsoft Graph, an Azure app registration per operator and an OAuth consent flow. It contradicts the self-hosted constraint and is a project of its own. The adapter interface is designed so it can be added later without touching the booking core. |
| Zoom, Google Meet, Webex | Same reason. |
| BigBlueButton native API | Possible without much work through its shared-secret checksum API, and a good second milestone, but not required for version 1. |

## Failure behaviour

A provider that cannot produce a link must never lose the booking.

- The booking is saved first. The conference link is attached afterwards, in the same request.
- If link creation fails, the booking stays confirmed, the e-mail is sent with the location text
  instead of a link, and the administrator sees the booking flagged as "conference link missing"
  with the reason. The flag and the reason are persisted in `conference_state` (`failed`) and
  `conference_error` of `#__appointments_bookings`, so the diagnostic survives the request that
  produced it. `conference_error` carries a short message only, never a credential, a token or a
  URL, because it is shown in a list view.
- Every abort for an active conference booking writes `failed`: no provider resolves for an event
  type with the location type `conference` (`No conference provider is selected for this event
  type.`), a provider or configuration error, and, as a guarded best-effort update after the
  rollback, a storage error. Only a stale snapshot stays inert, because the newer revision belongs
  to the request that wrote it.
- The provider follows saved `conference_params`, including `''`/`inherit` (the event form always
  saves them together with the column). Only when `conference_params` is NULL does the event
  type's `conference_provider` column apply before the component option, so a provider set only
  in the column is not replaced by a disabled global provider (release test 2026-10-22).
- A retry action exists in the administrator interface, and it sends an updated calendar entry with
  a raised `SEQUENCE` when it succeeds.
- A provider that contacts a remote host gets a hard timeout of five seconds and exactly one retry.
  A visitor must never wait on a third party.

## Teardown

When a booking is cancelled or anonymised, `destroyMeeting()` is called and the stored room
identifier and URL are erased. For providers where the room is implicit, this is a no-op and only
the stored values are erased.

## Tests

- The Jitsi room name is deterministic for a booking, has the configured entropy, and is not
  derivable from the `uid` without the key.
- A JWT is accepted by a reference verification with the configured secret, carries the right room,
  and is rejected before its validity window.
- The fixed-room provider returns the same URL for two bookings, and the administrator interface
  shows the collision warning.
- A forced provider failure leaves the booking confirmed with `conference_state = 'failed'` and a
  readable `conference_error`, and that message contains no credential, token or URL.
- A failing provider leaves the booking confirmed, sets the flag, and the e-mail still goes out with
  the ICS attached.
- `MeetingRequest` carries no personal data: a test asserts its property set.
