# OSMap J2Commerce Plugin

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/osmap-j2commerce.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/osmap-j2commerce.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-osmap-j2commerce.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-osmap-j2commerce.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Adds all publicly visible J2Store / J2Commerce products to the OSMap sitemap automatically.

### Compatibility Test Scope

The CI uses the official Joomla Docker images (newest Joomla 5.4.x and 6.x), the real OSMap 5.1.6 runtime, and real
J2Commerce/J2Store components, then exercises the plugin against them on both
stacks (Joomla 5 + J2Store/J2Commerce 4 and Joomla 6 + J2Commerce 6):

- **Real OSMap loader/dispatch** (`07-osmap-loader.php`): the plugin is matched
  and dispatched through OSMap's actual loader — `getPluginsForComponent()` →
  `getComponentElement()` (com_j2store vs com_j2commerce) → `getTree()` — against
  the real OSMap classes installed in the image, asserting the correct product
  URLs for both the `#__j2store_products` and `#__j2commerce_products` tables.
- **Real single-product emit** (`07-osmap-loader.php`, `03-plugin-class.php`):
  `emitSingleProduct()` is exercised against a real seeded product and asserts
  the emitted node/URL (no longer only the no-crash, non-existent-id case).
- **Real collection/emit path** (`03-plugin-class.php`, `03b-plugin-emit.php`):
  these run against the real OSMap `Collector`/`Item` classes (not stubs). Stubs
  are only used as a last-resort fallback if OSMap is not installed at all.
- **Full-stack HTTP sitemap** (`06-sitemap-http.php`): a real HTTP request to the
  live OSMap XML endpoint asserting product URLs.
- **J6 SEF URLs** (`08-sitemap-http-sef.php`): a dedicated SEF-enabled J6 job
  (`docker-compose.joomla6-sef.yml`, `J2COMMERCE_SEF=1`) asserts the live sitemap
  contains correctly-formed SEF product URLs on Joomla 6 + J2Commerce 6.

## Description

OSMap does not index J2Store or J2Commerce product pages out of the box.

This plugin bridges the gap: it registers with OSMap for `com_j2store` and
`com_j2commerce` menu items and emits one sitemap node per publicly visible
product.

For list views the plugin combines two mechanisms:

1. **Hidden child menu items (optional):** manually created hidden `com_content`
   article menu items (`published=-2`) below the shop menu item. Their `path`
   is used as the sitemap URL.
2. **Direct product query:** products are loaded from `#__content` joined with
   the products table. The URL is built from the parent menu item's SEF path
   plus the article alias.

Results are de-duplicated by article id.

Only publicly visible products are included (article published, within its
publish window, guest-accessible; product `enabled = 1` and `visibility = 1`).
New or re-enabled products are picked up on the next sitemap request.

## Features

- Adds all publicly visible J2Store / J2Commerce products to the OSMap sitemap automatically
- Builds SEF URLs from the shop menu item path and article alias (with language prefix)
- Configurable default priority and change frequency (plugin-wide)
- Excludes products that are not publicly visible (see *Description*)
- Patches OSMap ≤ 5.1.3 `Factory::getTable()` bug on install/update

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- J2Commerce (formerly J2Store) 4.x or later
- [OSMap Free or Pro](https://extensions.joomla.org/extension/osmap/) 5.x or later

> **OSMap ≤ 5.1.3 compatibility:** The installer script automatically patches a
> bug in OSMap ≤ 5.1.3 where `Factory::getTable()` returns `false` instead of
> `null`, causing a `TypeError` in PHP 8.1+. The patch is applied on install
> and update and is idempotent — OSMap ≥ 5.1.4 already contains the fix.

## Installation

1. Download `plg_osmap_j2commerce_<version>.zip` from the [latest release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases)
2. **System → Install → Extensions**
3. Upload and install
4. Enable via **System → Manage → Plugins → OSMap - J2Commerce**
5. In OSMap (**Components → OSMap → Sitemaps**), ensure the menu containing
   your shop item is selected
6. Save the sitemap — products appear automatically

### What the installer does

On every install and update, the installer script (`script.php`):

- disables the legacy `plg_osmap_j2store` plugin (folder `osmap`, element
  `j2store`) if it is present, because it generates wrong product URLs;
- registers the plugin's update site if it is not registered yet;
- removes update sites of this plugin that still point to the repository's
  former path (`advansit/Joomla`), so Joomla only queries the current update
  server;
- patches OSMap ≤ 5.1.3 (see above).

### .htaccess Requirements

This plugin generates SEF URLs of the form `/de/shop/product-alias`. For these
to resolve correctly, your `.htaccess` must route all non-file requests through
Joomla's `index.php` (standard Joomla SEF setup):

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/index\.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule .* index.php [L]
```

If your site blocks direct `com_content` or `component/` URLs (recommended for
SEO and security), ensure the shop component is explicitly allowed, as it is
used internally for cart and checkout: `/component/j2store` and
`option=com_j2store` for J2Store / J2Commerce 4, `/component/j2commerce` and
`option=com_j2commerce` for J2Commerce 6 (J2Commerce 6 uses `com_j2commerce`):

```apache
# Block /component/ URLs except j2store/j2commerce (cart/checkout) and ajax
RewriteCond %{REQUEST_URI} ^(/[a-z]{2})?/component/ [NC]
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/j2store [NC]
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/j2commerce [NC]
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/ajax [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ /$1/ [R=301,L]

# Block index.php?option=com_... except j2store/j2commerce and required components
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
RewriteCond %{QUERY_STRING} !^option=com_j2store [NC]
RewriteCond %{QUERY_STRING} !^option=com_j2commerce [NC]
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
RewriteRule ^([a-z]{2})?/?index\.php$ /$1/? [R=301,L]
```

Product pages (`/de/shop/product-alias`) are served entirely through Joomla's
SEF routing and do not require any additional rewrite rules.

## Updating

The manifest declares the update server
`https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_osmap_j2commerce/updates/update.xml`.
New versions appear under **System → Update → Extensions**. Alternatively,
install the newer ZIP over the existing installation.

## Uninstall

Uninstall the plugin via **System → Manage → Extensions**. The plugin has no
uninstall routine: the OSMap ≤ 5.1.3 patch and the disabled state of the legacy
`plg_osmap_j2store` plugin are not reverted.

## Configuration

**System → Manage → Plugins → OSMap - J2Commerce**

| Parameter | Default | Description |
|---|---|---|
| Priority | `0.8` | Sitemap priority for product pages |
| Change Frequency | `weekly` | How often search engines should re-crawl product pages |

OSMap's per-menu priority and change frequency settings take precedence over
the plugin defaults.

## Usage

### How It Works

OSMap calls `getTree()` for every menu item whose `option` matches the installed
component (`com_j2store` takes precedence if both are enabled; see
[Migrating from J2Store to J2Commerce](#migrating-from-j2store-to-j2commerce)).
For list views the plugin runs two URL mechanisms and de-duplicates their output
by article id.

#### Standard menu items (primary)

The plugin inspects the menu item's `view` parameter:

- `view=products` — all enabled products in the given category (`catid`) **and its sub-categories**, or all products if no `catid`
- `view=product` — the single product whose **article id** (`#__content.id`, not the product id) is given in `id`
- `view=categories` — all enabled products in the menu item's selected root category (`id`) **and its sub-categories**, or all products if the menu item has no category
- `view=categoryalias` — J2Commerce single-category alias; at runtime this redirects to `view=products` with the category's `id`. The plugin treats it identically: products of that category (and its sub-categories) are emitted.

Only publicly visible products are listed: the underlying `com_content` article
must be published (`state = 1`), currently within its publication window
(`publish_up` has started and `publish_down` has not passed, treating both
`NULL` and the J4/J5 null-date sentinel `0000-00-00 00:00:00` as no limit) and
readable by the guest view levels (`access`), and the product row
must be enabled and visible (`enabled = 1`, `visibility = 1`). On a multilingual
site every URL carries a language SEF prefix (e.g. `/de/shop/product-alias`) so
it resolves directly without a 301 redirect. For hidden children the child menu
item's language is used. For the direct product query the list menu item's
language is used; if the list menu item is set to *All* (`*`), the article's
language is used.

For list views (`products`, `categories`, `categoryalias`) **both** mechanisms
run: the published=-2 hidden children and the direct product
query. Their results are merged and de-duplicated by article id, so a leftover
hidden menu item never suppresses the rest of the catalogue. This works on any
standard J2Store or J2Commerce installation.

#### Hidden menu items (additional, site-specific)

Some installations manually create hidden `com_content` menu items
(`published=-2`) as children of the shop menu item, one per product. These
items carry the SEF path directly (e.g. `shop/product-alias`).

When present, the plugin queries `#__menu` for `published=-2` children, joins
`#__content` and the products table to verify each product is publicly visible,
and emits the menu item's `path` (with the language SEF prefix) directly as the
sitemap URL. This also runs for a shop menu item that has no `view` parameter.

## Development

### Structure

```
plg_osmap_j2commerce/
├── README.md
├── VERSION
├── LICENSE.txt
├── build.env
├── build.sh                    # Delegates to shared/build/build.sh
├── composer.json
├── j2commerce.xml              # Joomla manifest (group="osmap", element="j2commerce")
├── j2commerce.php              # OSMap entry point: class PlgOsmapJ2commerce extends J2CommerceNew
├── script.php                  # Installer script (postflight)
├── services/provider.php       # DI registration; loads the classes via require_once
├── src/Extension/
│   ├── J2Commerce.php          # Base class (com_j2store, #__j2store_products)
│   └── J2CommerceNew.php       # com_j2commerce, #__j2commerce_products
├── language/ (en-GB, de-DE, fr-FR)
├── updates/update.xml
└── tests/
```

Installed path: `plugins/osmap/j2commerce/`

### Building

```bash
./build.sh
```

## Automated Testing

This plugin has automated tests that run via GitHub Actions (`osmap-j2commerce.yml`) on pushes and pull requests to `main` that change this directory, `shared/**` or the workflow file. Besides the Joomla 5, Joomla 6 and Joomla 6 SEF jobs, CI runs a PHP syntax check, the language file lint, an update from the previous release and a production-like lane (newest Joomla 6.x, PHP 8.4, MariaDB 10.6, J2Commerce 6 production pin). CI always tests the newest Joomla 5.4.x and 6.x releases (no pinned patch version) and prints them in each job log (`Tested versions: …`); a red run can therefore be caused by a new Joomla release. Details: [testing.md](../../.claude/skills/joomla-extensions/references/testing.md).

### Test Suites

Order as in `tests/test.env`:

1. **Installation** — plugin registration in DB, file deployment
2. **Configuration** — plugin params, language files, XML manifest
3. **Plugin Class** — OSMap interface, `getTree()`/emit methods against the real
   OSMap `Collector`/`Item` classes, plus real single-product emit
4. **Plugin Emit** (`03b-plugin-emit.php`) — collection/emit path against the real
   OSMap `Collector`/`Item` classes
5. **Sitemap Output** — direct DB query test for `getTree()` result
6. **OSMap Loader** — dispatch through OSMap's real `getPluginsForComponent()` →
   `getComponentElement()` → `getTree()` loader on both stacks
7. **Sitemap HTTP** — full-stack HTTP request against the live sitemap endpoint
8. **Sitemap HTTP (SEF)** — J6-only SEF-enabled job asserting SEF-formed product
   URLs in the live sitemap
9. **Mixed Migration State** (`09-mixed-migration.php`) — J2Store and J2Commerce 6
   registered at the same time: while both components are enabled the plugin serves
   `com_j2store`, after `com_j2store` is disabled it serves `com_j2commerce`, and a
   menu item of a component without tables does not break the sitemap
10. **Installer Messages** — shared suite: removes and reinstalls the package through
    the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated
    language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a
    non-zero exit code
11. **Uninstall** — clean removal from database and filesystem

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`; for the Joomla 6 stacks also
`tests/j2commerce6.zip`, built from the J2Commerce 6 commit pinned in the workflow
(`7edb6e11ae9148bf996b06c47a0d8266865af7b2`, all lanes). Full commands:
[Local Prerequisites](../../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# in j2commerce/plg_osmap_j2commerce
./build.sh
mkdir -p tests
cp *.zip tests/extension.zip

cd tests
docker compose up -d
timeout 300 bash -c 'until docker exec plg_osmap_j2commerce_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# Joomla 6 (requires tests/j2commerce6.zip)
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec plg_osmap_j2commerce_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=plg_osmap_j2commerce_j6_test J2COMMERCE_STACK=j6 ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v

# Joomla 6 with SEF URLs (requires tests/j2commerce6.zip)
docker compose -f docker-compose.joomla6-sef.yml up -d
timeout 300 bash -c 'until docker exec plg_osmap_j2commerce_j6_sef_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=plg_osmap_j2commerce_j6_sef_test J2COMMERCE_STACK=j6 ./run-tests.sh sitemap-http-sef
docker compose -f docker-compose.joomla6-sef.yml down -v
```

`all` also runs `sitemap-http-sef`, which CI runs only in the SEF job; to mirror the
Joomla 5/Joomla 6 CI matrices, run the other suites by name. CI sets
`TEST_STRICT_SKIP=1` (a test that would SKIP fails).

## Troubleshooting

**Products do not appear in the sitemap**

1. Verify the plugin is enabled (**System → Manage → Plugins → OSMap - J2Commerce**)
2. Confirm the menu containing your shop item is selected in the OSMap sitemap
   configuration (**Components → OSMap → Sitemaps → edit → Menus tab**)
3. Verify that your shop menu item uses `view=products`, `view=product`,
   `view=categories` or `view=categoryalias` — open the menu item in
   **Menus → your menu** and check the link field
4. Run these queries in phpMyAdmin to diagnose the root cause (replace
   `jos_` with your actual table prefix):

```sql
-- Are there hidden menu children for the shop item?
SELECT m.id, m.title, m.path, m.published
FROM jos_menu m
WHERE m.published = -2
  AND m.client_id = 0
  AND m.parent_id = (
    SELECT id FROM jos_menu
    WHERE link LIKE '%option=com_j2%view=products%'
      AND published = 1 AND client_id = 0
    LIMIT 1
  );

-- Are there publicly visible products linked to Joomla articles? (J2Store / J2Commerce 4.x)
-- a.access = 1 is the Public level; the plugin accepts every view level a guest may see.
-- Dates are stored in UTC.
SELECT p.j2store_product_id, p.product_source, p.enabled, p.visibility, a.title, a.state, a.access
FROM jos_j2store_products p
JOIN jos_content a ON a.id = p.product_source_id
  AND p.product_source = 'com_content'
WHERE p.enabled = 1 AND p.visibility = 1
  AND a.state = 1 AND a.access = 1
  AND (a.publish_up IS NULL OR a.publish_up <= UTC_TIMESTAMP())
  AND (a.publish_down IS NULL OR a.publish_down >= UTC_TIMESTAMP())
LIMIT 20;

-- J2Commerce 6.x variant (use jos_j2commerce_products instead):
SELECT p.j2commerce_product_id, p.product_source, p.enabled, p.visibility, a.title, a.state, a.access
FROM jos_j2commerce_products p
JOIN jos_content a ON a.id = p.product_source_id
  AND p.product_source = 'com_content'
WHERE p.enabled = 1 AND p.visibility = 1
  AND a.state = 1 AND a.access = 1
  AND (a.publish_up IS NULL OR a.publish_up <= UTC_TIMESTAMP())
  AND (a.publish_down IS NULL OR a.publish_down >= UTC_TIMESTAMP())
LIMIT 20;
```

If the product query returns nothing, check product visibility, article access
level and publish dates (on older databases an open date may be stored as
`0000-00-00 00:00:00` instead of `NULL`; the plugin treats both as no limit).

If the product query still returns nothing without the `WHERE` conditions: the
products are not linked to Joomla articles via `com_content`. This is required —
J2Store / J2Commerce store products as Joomla articles with a corresponding row
in the products table.

**Sitemap stays empty without an error message**

OSMap silently ignores exceptions thrown by its plugins. The plugin therefore
logs failing queries via Joomla's `Log` API with the category
`plg_osmap_j2commerce` (level `ERROR`). These entries are only written if a
logger is configured for that category (for example via the logging options of
the *System - Debug* plugin).

**Only some products appear**

Only publicly visible products are included (article published, within its
publish window, guest-accessible; product `enabled = 1` and `visibility = 1`).
Check the product in **Components → J2Commerce → Products** and its article.

Hidden menu items are optional; products without one are still listed via the
direct query. A stale hidden item only affects that product's URL.

**SEF URLs are not resolved correctly**

Ensure Joomla's SEF is enabled (**System → Global Configuration → SEO Settings**)
and that the `.htaccess` / `web.config` rewrite rules are in place.

**TypeError: Factory::getTable() must be of type ?Table, false returned**

This is a bug in OSMap ≤ 5.1.3. The installer script patches it automatically
on plugin install or update. If you see this error before installing the plugin,
apply the fix manually:

```bash
sed -i 's/return Table::getInstance($tableName, $prefix);/return Table::getInstance($tableName, $prefix) ?: null;/' \
    /path/to/joomla/administrator/components/com_osmap/library/Alledia/OSMap/Factory.php
```

**Product URLs in the sitemap return 404**

Verify that:
1. Joomla's SEF is enabled and `.htaccess` routes non-file requests to `index.php`
2. `/component/j2store` (J2Commerce 6: `/component/j2commerce`) is not blocked in
   `.htaccess` (the component needs it for cart/checkout)
3. The shop menu item uses `view=products`, `view=product`, `view=categories` or
   `view=categoryalias` and the product is publicly visible (see above).
4. If using manually created `published=-2` hidden menu items: verify the
   `path` field is correct. If paths are stale, rebuild the menu tree
   (**Menus → All Menu Items**, toolbar button **Rebuild**; only shown to
   users with the *Super User* permission `core.admin`).

See the [.htaccess Requirements](#htaccess-requirements) section above.

**J2Store: products appear with wrong or outdated URLs**

The J2Store mechanism uses the `path` field of the `published=-2` menu item
directly as the sitemap URL. If product aliases were renamed or the menu tree
was modified without rebuilding, the stored paths may be stale. Open
**Menus → All Menu Items** and click **Rebuild** in the toolbar to regenerate
all menu item paths.

## Migrating from J2Store to J2Commerce

During a migration there is a short window in which **both** `com_j2store` and
`com_j2commerce` are installed and enabled at the same time. OSMap matches a
single plugin element to exactly one component per request, so while both
components are active the plugin resolves to only one of them (`com_j2store`
takes precedence) and the shop URLs for the other component are omitted from the
sitemap. **An empty shop sitemap during this window is expected — it is not a
defect.**

To avoid it, follow this order when migrating:

1. Import/verify products in J2Commerce.
2. **Disable J2Store** (**System → Manage → Extensions**) *before* publishing the
   new J2Commerce shop menu items.
3. Publish the J2Commerce menu items and rebuild the sitemap.
4. Verify the sitemap now lists the J2Commerce product URLs.

Keeping both components permanently enabled side by side is not supported by a
single plugin element; contact us if you need that.

## Multi-Language Support

- English (`en-GB`)
- German (`de-DE`)
- French (`fr-FR`)

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Copyright (C) 2026 Advans IT Solutions GmbH

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE.txt](LICENSE.txt) for the full license text.

SPDX-License-Identifier: `GPL-3.0-or-later`
