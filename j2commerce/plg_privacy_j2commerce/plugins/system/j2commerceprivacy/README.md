# J2Commerce Privacy System Plugin

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Installation and updates:** see the [shared backup/approval policy](../../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_system_j2commerceprivacy` is bundled with [Privacy - J2Commerce](../../../README.md). It integrates J2Commerce 6 checkout consent and links administrator-created Joomla privacy-request mail to the parent's guest confirmation page. It is not a standalone public privacy-request service.

## Features

- Renders and validates the configured checkout consent through J2Commerce 6 events.
- Records consent only for an accepted order, tied to the same cart, once per order.
- Clears checkout consent session state on cleanup.
- Rewrites `{TOKENURL}` and `{FORMURL}` in the two Joomla-owned administrator request templates when guest confirmation is enabled.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, and the installed/enabled parent privacy plugin. Checkout integration requires J2Commerce 6.3.4+ with templates firing `AfterDisplayShippingPayment`. Guest confirmation requires Joomla `com_privacy`, the parent callback and configured mail. J2Store/J2Commerce 4 uses the parent's checkout override instead; it does not gain server-side consent recording from this plugin.

## Installation

Install the parent privacy ZIP. Its installer installs/enables **System - J2Commerce Privacy** when first introduced. No separate child download is required. Review the parent's [checkout setup and limits](../../../README.md#checkout-consent-checkbox) before activation.

## Updating

Update the parent ZIP; the child has no independent release/update feed. Existing enabled/disabled state is preserved. Custom shop template overrides remain operator-maintained and can prevent the required checkout event from firing.

## Uninstall

The parent's uninstall removes the bundled child and its extension registration. Existing shop data, consent evidence, action logs and deployed template overrides follow the parent's [uninstall contract](../../../README.md#uninstall), not a blanket child-data purge. Disabling the child does not anonymise previously recorded data.

## Configuration

No own parameters are declared. The enabled parent's **Show Consent Checkbox**, **Consent Required**, policy article/text and **Guest Request Confirmation** options control behaviour. With required consent, a missing/invalid cart cannot establish accepted consent.

## Usage and Permissions

Customers use the normal J2Commerce checkout. Joomla administrators create and process privacy requests through Core. Only `com_privacy.notification.admin.export` and `.admin.remove` have their links rewritten; the mail subjects/bodies remain Joomla-owned. Follow [guest confirmation](../../../README.md#process-for-a-guest-customer) for token expiry, exact address matching and rate limits enforced by the parent.

## Privacy and Data Handling

The parent's `ConsentRepository` records consent in `#__privacy_consents`, including order evidence, IP address and user agent. The parent removal/retention paths remove personal evidence according to its configured rules; the consent record itself is not automatically deleted. Operators must describe purpose and retention in their privacy policy. Guest evidence lookup is limited to the session's order, not all orders with the same address.

This child adds no own tables, Scheduler routine or mail transport. Disabling it removes its checkout validation/mail-link integration; it is not a safe way to bypass a required consent or finish a privacy request.

## Development

Use the [parent build](../../../README.md#development), which includes both children. No separate child release/version bump is required.

## Automated Testing

The parent registers **consent_render**, **consent_logging** and **data_isolation** suites, plus its JavaScript consent-validator tests. See [parent test suites and commands](../../../README.md#automated-testing). Optional integration fixtures and rendered markup are not a full acceptance claim for every shop version/template.

## Troubleshooting

Missing checkbox: verify both plugins, parent options, J2Commerce version and the checkout template event. Required checkout refusal: inspect cart/session/token and consent state rather than recording retroactive consent. Unchanged guest mail link: verify the exact administrator template and parent guest-confirmation option.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent lint covers the child language files.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
