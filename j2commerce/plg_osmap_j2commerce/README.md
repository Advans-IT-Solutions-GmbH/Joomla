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
re-enabled products are picked up on the next sitemap request.

## Requirements

- Joomla 5.x or 6.x
- J2Commerce (formerly J2Store) 4.x or later
- [OSMap Free](https://extensions.joomla.org/extension/osmap/) 5.x or later

## Installation

1. Download `plg_osmap_j2commerce.zip` from the [latest release](https://github.com/advansit/Joomla/releases)
2. Install via Joomla Backend → **Extensions → Install**
3. Enable the plugin: **Extensions → Plugins** → search "OSMap J2Commerce" → enable
4. In OSMap (**Components → OSMap → Sitemaps**), ensure the menu containing
   your shop item is selected
5. Save the sitemap — products appear automatically

## Configuration

In the plugin parameters (**Extensions → Plugins → OSMap J2Commerce**):

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

The sitemap node link (`index.php?option=com_content&view=article&id=X&Itemid=Y`)
uses the product's own menu item as `Itemid`, so Joomla's SEF router resolves
it to the correct `/de/shop/product-alias` URL.

## Prerequisites

Each product must have a `published=-2` child menu item under the shop menu
item. J2Commerce creates these automatically when you save a product — they
appear in the menu manager as hidden entries with the product alias as path.
If a product was created before this plugin was installed, its menu item
already exists and will be picked up immediately.

If you manage products outside of J2Commerce's standard workflow (e.g. direct
DB imports), ensure the corresponding menu items exist.

## Troubleshooting

**Products do not appear in the sitemap**

- Confirm the plugin is enabled (Extensions → Plugins → OSMap J2Commerce).
- Confirm the menu containing your shop item is selected in the OSMap sitemap
  configuration (Components → OSMap → Sitemaps → edit → Menus tab).
- Check that the product has `enabled=1` in J2Commerce
  (Components → J2Commerce → Products).
- Verify that `published=-2` child menu items exist under the shop menu item
  (Extensions → Menus → your menu → show all items including hidden).

**Only some products appear**

Products with `enabled=0` are intentionally excluded. Check the product status
in J2Commerce.

**SEF URLs are not resolved correctly**

Ensure Joomla's SEF is enabled (System → Global Configuration → SEO Settings)
and that the `.htaccess` / `web.config` rewrite rules are in place.

## Changelog

### 1.0.0 (2026-04)

- Initial release
