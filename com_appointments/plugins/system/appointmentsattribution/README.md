# Appointments Origin of Bookings

**Installation and updates:** see the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_system_appointmentsattribution` is bundled in [com_appointments](../../../README.md). It records in the visitor's Joomla session how they reached the site, on every page of the site, so that a booking made later in the same session stores its origin. A landing page can be any page, for example a Joomla article with campaign parameters whose button leads to the booking page.

What is recorded, stored and shown, and for how long, is described in the component README, [Origin of bookings](../../../README.md#origin-of-bookings-campaign-attribution).

## Features

- Handles `onAfterInitialise` on the site only, for GET requests of HTML pages (no Ajax, raw or JSON request).
- Keeps the first landing (time, path and query, address), the number of page views, the last page and the first and last touch: a page view with a `utm_*` parameter or a click ID, or with a referrer from another host.
- Server side only: no cookie of its own, no script, no form field.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+ and Appointments.

## Installation

Install the owning component ZIP. Its installer installs and enables **System - Appointments origin of bookings** when it is first introduced, also on an update. No separate download is required.

## Updating

Update through the component ZIP. Existing plugin enablement is preserved. The plugin has no release or update feed of its own.

## Uninstall

Component uninstall removes the plugin. Disabling the plugin stops recording on pages that are not pages of the component. The component option **Origin of bookings** switches recording and storing off altogether.

## Configuration

No own parameters. The component option **Origin of bookings** (`attribution_capture`, default on) decides.

## Privacy and Data Handling

The session holds the recorded values until the session ends. Joomla stores the session as configured (database or files), and it ends with the configured session lifetime. A booking copies the values into its encrypted personal fields, see the component README.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent language lint includes the plugin.

## Support & Contact

Advans IT Solutions GmbH, https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
