# DMARC MTA-STS Policy System Plugin

## Description

`plg_system_dmarcmtasts` serves the MTA-STS policy (RFC 8461) for the DMARC Reports component.
It answers requests to `https://mta-sts.<domain>/.well-known/mta-sts.txt` with a policy document
generated dynamically from the component's settings, so there is no hand-maintained static file.

The plugin only serves the policy. Publishing the `mta-sts` subdomain with valid TLS, the
`_mta-sts` / `_smtp._tls` DNS records and one web-server rewrite rule (see
[Web-server rule](#web-server-rule)) are manual steps; the component's Action Center spells out the
DNS records exactly.

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

**Availability:** No system-plugin release or `system-dmarcmtasts-v*` tag has been published yet.
Build from source with `cd plg_system_dmarcmtasts` and `./build.sh` (Bash, rsync and zip required),
or build the [DMARC Reports package](../pkg_dmarcreports) for the complete solution.
Individual buildability is retained; it does not imply an existing release download.

Install the built ZIP like any Joomla extension; the plugin is enabled automatically on a fresh install. It is
also bundled in `pkg_dmarcreports`, the recommended way to install the whole DMARC solution.

Standalone installs register their own update server; package installs use the package channel.
Updates become downloadable when releases are published. Release retention is latest-only per
extension/tag family, not the monorepo's global latest release.

## Configuration

Configure everything in the DMARC Reports component options (Options → MTA-STS): enable MTA-STS,
choose the mode, list the MX hosts, set `max_age` and the TLS-RPT reporting address. Then publish
the DNS records and the `mta-sts` subdomain shown in the component's Action Center.

The MX list is shared by all monitored domains. A union of their receiving MX hosts is valid,
but every domain's actual DNS MX targets must be covered before `enforce`. Enable the component's
live diagnostics and check the domain detail Action Center or System Check. Wildcards match only
one left-most label: `*.example.org` does not cover `example.org` or `mail.eu.example.org`.
Uncovered hosts can defer delivery and eventually cause bounces under an enforcing policy;
DNS failures or absent usable MX records are not proof of coverage. Also verify SMTP STARTTLS,
MX certificates and TLS-RPT results before rollout.

Serving this endpoint is inbound HTTP(S) traffic. The component's optional live validation is
separate: it performs outbound DNS and HTTPS policy fetching when diagnostics are enabled.
Neither policy hosting nor validation uploads report contents.

### DNS / host step the extension cannot do

MTA-STS requires the policy to be reachable at `https://mta-sts.<domain>/.well-known/mta-sts.txt`
with a valid certificate for `mta-sts.<domain>`. Point that subdomain at this Joomla site (or a host
that serves this endpoint) and publish the `_mta-sts.<domain>` and `_smtp._tls.<domain>` TXT records
from the Action Center. The extension never writes DNS.

### Web-server rule

Senders request exactly `https://mta-sts.<domain>/.well-known/mta-sts.txt`. The plugin answers from
inside Joomla, so the web server must hand that exact path to Joomla's `index.php`; otherwise the
web server answers the `.txt` request itself (usually with a 404) and the plugin never runs. Joomla's
generic fallback rule in `htaccess.txt` only applies once the file is active as `.htaccess`, and only
to paths that are not an existing file or directory, so a site without it, with a customised or
older rule set, or with a leftover static `mta-sts.txt` does not serve the generated policy.

On Apache, add this rule to the site's `.htaccess` directly after `RewriteEngine On`, before
Joomla's own rewrite rules. It routes the exact path to Joomla independently of the rules below it
and takes precedence over a static file at the same path:

```apache
# MTA-STS (RFC 8461): let Joomla answer the policy request.
RewriteCond %{QUERY_STRING} ^$
RewriteRule ^\.well-known/mta-sts\.txt$ index.php [L]
```

The condition is part of the rule. Senders request the path without a query. Without the
condition, `/.well-known/mta-sts.txt?option=com_content` would be handed to Joomla too, and a site
that exempts internal rewrites from its own rules (`RewriteCond %{ENV:REDIRECT_STATUS} !^$`) would
then let that query pass its `?option=` restrictions on the second pass.

The document root of the `mta-sts.<domain>` host must be the Joomla root, because the policy path
is fixed at the root of that host. On any other web server, route the same exact path to
`index.php`. The package's integration tests install this rule
and request the exact URL (with a stale static file in place that must not be served), so the
documented setup is the tested one. They also check that the rule does not route the path with a query string.

### Why does `/.well-known/mta-sts.txt` answer 404?

The plugin answers only when every condition below holds. Otherwise the request falls through to
the web server or to Joomla, which answer 404. Check them in this order:

1. **Web-server rule.** The rule above stands directly after `RewriteEngine On`. Without it Apache
   serves the `.txt` path itself. On a site with its own hardening rules (for example blocks for
   `/component/` or `?option=`), the rule must stand before them: it has no query string and no
   `/component/` path, so those rules do not catch it once it has run.
2. **Host.** `mta-sts.<domain>` resolves to this server, the web server serves that host from the
   Joomla root (an alias of the site or its own virtual host with the same document root) and has a
   valid certificate for it. A request to the main host never receives the policy.
3. **Plugin.** *System - DMARC MTA-STS Policy* is enabled.
4. **Component options.** MTA-STS is enabled (`mtasts_enable`) and the MX list is valid.
5. **Domain.** `<domain>` is listed under **Domains** and **published**. A domain that an import
   created automatically is unpublished until an administrator publishes it, so no policy is
   served for it before.

To test without DNS, send the host header yourself, for example
`curl -i -H "Host: mta-sts.example.org" https://www.example.org/.well-known/mta-sts.txt` (with
`-k` while the certificate does not cover the main host name). A `200` with `version: STSv1` proves
conditions 1 and 3 to 5. Condition 2 needs the real host name.

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
