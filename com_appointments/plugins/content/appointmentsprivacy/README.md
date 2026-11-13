# Appointments Privacy Completion Guard

**Installation and updates:** see the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_content_appointmentsprivacy` is bundled in [com_appointments](../../../README.md). It independently prevents a native Joomla privacy removal request from being marked Complete while Appointments reports incomplete processing. It is not a request form or an erasure engine.

## Features

- Handles Joomla's `onContentBeforeSave` event for native privacy request rows.
- Checks removal requests whose proposed status is Complete (`2`).
- Rejects the save with a translated error while the component's persisted `PrivacyOutcome` is incomplete.
- Leaves other request types, statuses and content saves outside this check.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, Appointments and its platform requirements, and Joomla `com_privacy`. It does not depend on the standalone guest-request adapter for its completion veto.

## Installation

Install the owning component ZIP. Its installer installs/enables **Content - Appointments privacy completion guard** when first introduced. No separate child download is required.

## Updating

Update through the component ZIP; existing plugin enablement is preserved. The child has no independent release/update feed. Verify the guard remains enabled before resuming native processing.

## Uninstall

Component uninstall removes the child. Disabling or removing it stops this independent veto; it does not resolve an incomplete privacy outcome, erase remaining data or make a request complete. Follow the [component uninstall/backup policy](../../../README.md#uninstall). Never disable the guard to force completion.

## Configuration

No own parameters are declared. Actual cleanup, scope warnings and persisted outcomes are owned by the component's privacy services, not by a checkbox in this plugin.

## Usage and Permissions

Use the genuine authorised Joomla Core Remove and separate Complete actions. Resolve reported cleanup/decryption/scope issues, retry native Remove and assess the remaining scope before Complete. The plugin adds no administrator permission or bypass and does not create, confirm, export or erase a request. [Formal privacy requests](../../../README.md#formal-privacy-requests) describes the owning workflow.

## Privacy and Data Handling

The guard reads the component's persisted request outcome and rejects a native status change when incomplete; it has no separate tables, mail transport or Scheduler routine. A successful native Complete is not proof of website-wide erasure or deletion of external copies, backups or legally retained records.

## Development

Use the [component build](../../../README.md#development). The component ZIP distributes and versions this child; do not copy it separately into a running site.

## Automated Testing

The [guest-request integration test setup](../../../../plg_system_privacyrequests/tests/README.md) explains isolated prerequisites and limitations. Its `appointments.cjs` exercises native Complete refusal even with the generic adapter disabled, partial cleanup failures, repair/retry and separate completion. These scenarios do not establish positive native Unicode-mail delivery or a complete deployment/browser acceptance matrix. Never install test probes on a live site.

## Troubleshooting

Complete refused: inspect the component's actual incomplete result, resolve the underlying cleanup or remaining-scope condition and retry native Remove. A receipt, audit attempt, successful mail send or manually checked review box is not evidence that the underlying remainder is gone. Check plugin enablement if the independent veto is missing; do not treat that absence as successful erasure.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent language lint includes the child.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
