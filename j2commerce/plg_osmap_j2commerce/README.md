# OSMap J2Commerce Plugin

Adds all enabled J2Commerce products to the OSMap sitemap automatically.

## Description

OSMap does not index J2Commerce product pages out of the box. J2Commerce uses
`published=-2` menu items for SEF URL generation — a status that OSMap's
built-in Joomla plugin skips by design.

This plugin bridges the gap: it registers with OSMap for `com_j2store` menu
items (the shop overview) and emits one sitemap node per enabled product, using
the same routing mechanism as J2Commerce itself. The result is correct SEF URLs
(`/de/shop/product-alias`) in the sitemap without any manual maintenance.

Products with `enabled=0` in J2Commerce are excluded automatically. New or
re-enabled products are picked up on the next sitemap request. Each product
must have a `published=-2` child menu item under the shop menu item —
J2Commerce creates these automatically when a product is saved.

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.x or 6.x
- PHP 8.1 or higher
- J2Commerce (formerly J2Store) 4.x or later
- [OSMap Free](https://extensions.joomla.org/extension/osmap/) 5.x or later

## Installation

1. Download `plg_osmap_j2commerce.zip` from the [latest release](https://github.com/advansit/Joomla/releases)
2. **System → Extensions → Install**
3. Upload and install
4. Enable via **System → Plugins → OSMap J2Commerce**
5. In OSMap (**Components → OSMap → Sitemaps**), ensure the menu containing
   your shop item is selected
6. Save the sitemap — products appear automatically

## Configuration

**System → Plugins → OSMap J2Commerce**

| Parameter | Default | Description |
|---|---|---|
| Priority | `0.8` | Sitemap priority for product pages |
| Change Frequency | `weekly` | How often search engines should re-crawl product pages |

OSMap's per-menu priority and change frequency settings take precedence over
the plugin defaults.

## How It Works

OSMap calls `getTree()` for every menu item belonging to `com_j2store`. The
plugin queries all `published=-2` child menu items of that shop item — these
are the hidden routing entries J2Commerce creates automatically for each
product. For each entry, the plugin checks that the corresponding J2Commerce
product exists and has `enabled=1`, then emits a sitemap node.

The sitemap node uses the `path` of the `published=-2` menu item directly as
the URL (e.g. `/shop/product-alias`). Joomla's SEF router converts this to a
fully qualified URL (`https://example.com/de/shop/product-alias`).

**This plugin requires J2Commerce-based routing.** Product pages must be
accessible via the shop SEF URL (`/de/shop/product-alias`). Direct
`com_content` article URLs (`/de/component/content/article/...`) are not used
and must not be required for product access.

## .htaccess Requirements

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
SEO and security), ensure `/component/j2store` is explicitly allowed, as
J2Commerce uses it internally for cart and checkout:

```apache
# Block /component/ URLs except j2store (cart/checkout) and ajax
RewriteCond %{REQUEST_URI} ^(/[a-z]{2})?/component/ [NC]
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/j2store [NC]
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/ajax [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ /$1/ [R=301,L]

# Block index.php?option=com_... except j2store and required components
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
RewriteCond %{QUERY_STRING} !^option=com_j2store [NC]
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
RewriteRule ^([a-z]{2})?/?index\.php$ /$1/? [R=301,L]
```

Product pages (`/de/shop/product-alias`) are served entirely through Joomla's
SEF routing and do not require any additional rewrite rules.

## Development

### Structure

```
plg_osmap_j2commerce/
├── README.md
├── VERSION
├── LICENSE.txt
├── plg_osmap_j2commerce.xml    # Joomla manifest (group="osmap", element="j2commerce")
├── j2commerce.php              # OSMap entry point + class_alias bridge
├── build.sh
├── services/provider.php       # PSR-4 registration
├── src/Extension/J2Commerce.php
├── language/ (en-GB, de-DE)
└── tests/
```

Installed path: `plugins/osmap/j2commerce/`

### Building

```bash
./build.sh
```

## Automated Testing

This plugin has automated tests that run on every push via GitHub Actions.

### Test Suites

1. **Installation** — Plugin registration in DB, file deployment
2. **Configuration** — Plugin params, language files, XML manifest
3. **Plugin Class** — OSMap interface, `getTree()` method, class loading
4. **Sitemap Output** — Direct DB query test for `getTree()` result
5. **Uninstall** — Clean removal from database and filesystem
6. **Sitemap HTTP** — Full-stack HTTP request against the live sitemap endpoint

### Running Tests Locally

```bash
cd tests
docker compose up -d
sleep 120  # Wait for Joomla initialization
./run-tests.sh all
docker compose down -v
```

## Troubleshooting

**Products do not appear in the sitemap**

- Verify the plugin is enabled (**System → Plugins → OSMap J2Commerce**)
- Confirm the menu containing your shop item is selected in the OSMap sitemap
  configuration (**Components → OSMap → Sitemaps → edit → Menus tab**)
- Check that the product has `enabled=1` in J2Commerce
  (**Components → J2Commerce → Products**)
- Verify that `published=-2` child menu items exist under the shop menu item
  (**System → Menus → your menu**, enable "Show hidden items")

**Only some products appear**

Products with `enabled=0` are intentionally excluded. Check the product status
in J2Commerce.

**SEF URLs are not resolved correctly**

Ensure Joomla's SEF is enabled (**System → Global Configuration → SEO Settings**)
and that the `.htaccess` / `web.config` rewrite rules are in place.

**Product URLs in the sitemap return 404**

Verify that:
1. Joomla's SEF is enabled and `.htaccess` routes non-file requests to `index.php`
2. `/component/j2store` is not blocked in `.htaccess` (J2Commerce needs it for cart/checkout)
3. The shop menu item is published and the product has `enabled=1` in J2Commerce

See the [.htaccess Requirements](#htaccess-requirements) section above.

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
