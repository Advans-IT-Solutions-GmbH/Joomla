# Appointments Dashboard Module (mod_appointments)

**Installation and updates:** the [shared installer conflict and recovery policy](../../../README.md#installation-safety-and-repeatable-system-checks) applies to the owning component.

## Description

`mod_appointments` is the administrator module **Appointments - Next appointments**. It is bundled in [com_appointments](../../README.md) and is not an independently released download.

## Features

- Lists the next pending or confirmed appointments (setting **Number of appointments**, 1 to 20, default 5) with date and time in the time zone of the website, the event type and the name of the booking person. Pending appointments are marked «Awaiting approval» and each entry links to its booking.
- Shows the number of bookings made since the user's last visit. The module stores the time of the last view of the dashboard per account in `#__user_profiles` (`mod_appointments.lastvisit`). On the very first visit there is no number. Erased bookings are not counted.
- Links to the bookings list of the component.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+ and the Appointments component.

## Installation

Install the Appointments package (`pkg_appointments_<version>.zip`). On its first installation the package publishes the module once at the position `cpanel` of the Home Dashboard with the title «Next appointments» in the installing administrator's language.

## Updating

Update through the Appointments package. An update leaves position, title, state and settings as they are. The module has no release or update feed of its own.

## Uninstall

Uninstalling the package removes the module with its instances and the stored visit times.

## Configuration

One own option, **Number of appointments** (1 to 20, default 5). Under **System → Administrator Modules** you can also change title, position, access level and state, and in the tab Advanced the layout and the module class suffix. The tab «Help» of the module edit screen explains the module.

## Usage and Permissions

The module follows the bookings list exactly: it needs «Access Administration Interface» on the component and «Edit» on at least one event type, and shows only the bookings of the event types the account may edit. Everybody else gets no output at all, not even the title. The help page of the component, section «Permissions», explains how to give another account access.

## Privacy and Data Handling

The module shows names of booking persons, which are personal data, only to accounts with the permissions above. Besides the last-visit time per account it stores nothing.

## Troubleshooting

The module is missing on the dashboard: check that it is published at the position `cpanel` and that the account has the permissions above. «The appointments could not be loaded.»: the cause is in the Joomla log (category `com_appointments`). A time differs from the bookings list: the module shows the time zone of the website, the bookings list shows UTC.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE.

## Support & Contact

Advans IT Solutions GmbH, https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).