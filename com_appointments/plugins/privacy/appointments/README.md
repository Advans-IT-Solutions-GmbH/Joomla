# Appointments Privacy Plugin

**Installation and updates:** the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks) applies to the owning component.

## Description

`plg_privacy_appointments` integrates Appointments records with Joomla's native Privacy Suite. It is bundled in [com_appointments](../../../README.md), not an independently released download.

## Features

- Exports primary-invitee booking records, decoded custom answers and field labels.
- Exports a separate, restricted invitation record when the request address is an additional guest.
- Handles formal removal through the component's privacy service, including its legal blocker, erasure mode and audit path.
- Searches internet-calendar contents within the handling administrator's unlocked access and exports/removes matching entries and account-specific calendar data. Entries with the address of a team member are never changed automatically; their number is reported for review.
- Exports and removes the staff data of the account Joomla found (blocked periods, host feed credential; hosted event types and owned schedules are exported and named for review, never changed), also with the internet calendars off.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, the owning Appointments component and its platform requirements, and Joomla `com_privacy`. The component requires sodium and MySQL/MariaDB. The plugin relies on the component's services and encrypted records.

## Installation

Install the component ZIP through Joomla's extension installer. The component installs and enables **Privacy - Appointments** on first introduction. Configure privacy policy and erasure behaviour before carrying out real requests.

## Updating

Update the component, not a separately assembled child ZIP. The installer updates the bundled plugin and preserves an existing enabled/disabled state. There is no child release/update feed.

## Uninstall

The component's uninstall removes this plugin. Follow the [component's retention/delete choice and backup instructions](../../../README.md#uninstall); uninstall is not an individual privacy request. Disabling the plugin stops its callbacks and does not erase stored records.

## Configuration

There are no plugin-specific parameters. Use component **Options** for erasure mode and **Legal reason to block formal removal**. That blocker applies to formal requests, not automatically to signed self-service erasure or retention; keep those operator policies consistent.

## Usage and Permissions

Use Joomla's confirmed privacy-request workflow with a genuinely authorised administrator. The plugin subscribes to `onPrivacyExportRequest`, `onPrivacyCanRemoveData` and `onPrivacyRemoveData`; it supplies no public request form or authentication bypass. See [Formal privacy requests](../../../README.md#formal-privacy-requests) for the full account/guest matching and administrator workflow.

## Privacy and Data Handling

The request email is authoritative. Additional-guest matching is per complete normalized address, not substring or accent-insensitive collation. A guest receives no primary-invitee contact data from another person's booking. Guest removal removes only that address from the guest list and audits the action; primary-invitee removal uses the shared anonymisation/deletion transaction and future host-cancellation notice.

The plugin has no separate tables, Scheduler routine or mail transport. Exports, delivered mail, calendar clients and backups need their own operator handling; a local callback does not erase external copies.

### Internet-calendar scope and limits

There is no searchable address index. Calendar entries can be searched only in calendars the handling administrator can unlock with effective key-holding access in the current password/MFA session. Exported entries redact other attendees/addresses and exclude unneeded properties; account exports exclude hashes, salts and key material. Calendar removal deletes matching entries and performs account device/key/grant/invitation cleanup; owned calendars keep their contents with owner `0`. Calendar audit rows retain their separate twelve-month retention.

Calendar search/removal has a **20-second** budget, **20,000 objects** across searched calendars and **16 MiB per calendar**; calendar export is bounded to **4 MiB** and account log export to **5,000 rows**. Unreadable booking rows and unsearchable calendars produce explicit incomplete results/warnings, not evidence that no data exists. The administrator must assess that result before declaring a request complete. The [component privacy section](../../../README.md#privacy-requests-and-retention) explains coverage and inaccessible calendars.

## Development

Build the owning component with its `./build.sh`; do not package the plugin separately. See [component development](../../../README.md#development) for prerequisites and shared build rules.

## Automated Testing

The component's `privacy:14-privacy.php` suite exercises formal export/removal, guest isolation and legal blocking. Other component suites cover erasure, lifecycle and mail. Use the [component's test commands](../../../README.md#running-tests-locally); there is no separate child test stack. Existing automated suites do not establish deployment or manual browser acceptance.

## Troubleshooting

Missing booking data: verify the plugin is enabled and compare the request address and its role in the booking. Blocked removal: read the configured legal reason and component errors; do not disable privacy callbacks merely to report a successful request.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR** are shipped. German uses Swiss High German. The component's language lint covers the child files; a new locale requires a repository-wide decision.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
