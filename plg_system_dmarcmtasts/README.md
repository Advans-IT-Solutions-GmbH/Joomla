# DMARC MTA-STS Policy System Plugin

## Description

`plg_system_dmarcmtasts` serves the MTA-STS policy (RFC 8461) for the DMARC Reports component.
It answers requests to `https://mta-sts.<domain>/.well-known/mta-sts.txt` with a policy document
generated dynamically from the component's settings, so there is no hand-maintained static file.

The plugin only serves the policy. Publishing the `mta-sts` subdomain with valid TLS and the
`_mta-sts` / `_smtp._tls` DNS records is a manual DNS step that the component's Action Center
spells out exactly.

## Features

- Dynamic `/.well-known/mta-sts.txt` output (mode `none`/`testing`/`enforce`, MX hosts, `max_age`).
- Reads the policy from `com_dmarcreports` parameters — one place to configure.
- Content-derived policy `id`, so the component can detect drift against the published DNS record.
- Does nothing unless MTA-STS is enabled and a valid MX list is configured.

## Requirements

- Joomla 5.4 or newer (tested on the latest 5.4.x and 6.x).
- PHP 8.1 or newer.
- The `com_dmarcreports` component installed, with MTA-STS enabled in its options.

## Installation

Install like any Joomla extension; the plugin is enabled automatically on a fresh install. It is
also bundled in `pkg_dmarcreports`, the recommended way to install the whole DMARC solution.

## Configuration

Configure everything in the DMARC Reports component options (Options → MTA-STS): enable MTA-STS,
choose the mode, list the MX hosts, set `max_age` and the TLS-RPT reporting address. Then publish
the DNS records and the `mta-sts` subdomain shown in the component's Action Center.

### DNS / host step the extension cannot do

MTA-STS requires the policy to be reachable at `https://mta-sts.<domain>/.well-known/mta-sts.txt`
with a valid certificate for `mta-sts.<domain>`. Point that subdomain at this Joomla site (or a host
that serves this endpoint) and publish the `_mta-sts.<domain>` and `_smtp._tls.<domain>` TXT records
from the Action Center. The extension never writes DNS.

## Development

### Structure

```
plg_system_dmarcmtasts/
├── dmarcmtasts.xml           # manifest (type=plugin, group=system)
├── script.php                # installer script (enables the plugin on install)
├── services/provider.php     # DI service provider
├── src/Extension/            # DmarcMtaSts plugin class (onAfterInitialise)
├── language/                 # en-GB, de-DE, fr-FR (.ini + .sys.ini)
└── updates/update.xml        # update server manifest
```

## Multi-Language Support

Ships with en-GB, de-DE (Swiss High German) and fr-FR, each with `.ini` and `.sys.ini`.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later. See `LICENSE.txt`.
