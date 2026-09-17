# J2Commerce Product Compare Plugin

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-product-compare.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-product-compare.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-productcompare.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-productcompare.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

The J2Commerce Product Compare Plugin adds a visual comparison feature to your store. Customers can select multiple products and view them side-by-side in an elegant modal interface. A persistent comparison bar keeps track of selected products across pages. Fully responsive design works on all devices, with configurable maximum products and customizable styling to match your store's theme.

## Features

- Side-by-side product comparison
- Comparison bar with thumbnails
- Modal comparison view
- Add from list and detail pages
- Configurable maximum products (default: 4)
- Selection stored in the browser (`localStorage`)
- Responsive design
- Customizable button styling
- Comparison table loaded via AJAX

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- J2Commerce 4.x (`#__j2store_*` tables) or J2Commerce 6.x (`#__j2commerce_*` tables)

### J2Commerce Version Compatibility

The plugin decides at runtime which shop is active; tables alone do not decide, because a migrated site has both table sets:

1. `com_j2commerce` enabled and `#__j2commerce_products` present → J2Commerce 6
2. otherwise `com_j2store` enabled and `#__j2store_products` present → J2Store / J2Commerce 4
3. otherwise (no active shop component) → J2Commerce 6 if `#__j2commerce_products` exists, else J2Store

The plugin manifest uses `group="j2commerce"`. On Joomla 6 the plugin is loaded from `plugins/j2commerce/productcompare/`. On Joomla 5 the installer script creates a mirror in `plugins/j2store/productcompare/` and registers the plugin with `folder=j2store` so that J2Store 4 can dispatch events to it.

**J2Commerce 4.x (Joomla 5)**:
- The plugin subscribes to the events J2Store 4.1.4 fires in its product layouts (`app_bootstrap3`/`app_bootstrap4`): `onJ2StoreAfterAddToCartButton` adds the button in product lists after the add-to-cart button (the call in the detail layout `view_cart` is skipped), `onJ2StoreAfterProductDisplay` adds it at the end of the product page.
- **Limitation:** J2Store 4 has no per-item list event of its own. Template overrides of the J2Store product layouts that do not call these two events show no compare button.
- DB tables: `#__j2store_products`, `#__j2store_variants`, `#__j2store_product_options`
- AJAX URL: `group=j2store`

**J2Commerce 6.x (Joomla 6)**:
- DB tables: `#__j2commerce_products`, `#__j2commerce_variants`, `#__j2commerce_product_options`
- AJAX URL: `group=j2commerce`
- The plugin subscribes to `onJ2CommerceAfterProductListItemDisplay` and `onJ2CommerceAfterProductDisplay` via `SubscriberInterface`.

On both versions the AJAX handler `onAjaxProductcompare` and the page handlers `onAfterDispatch`/`onAfterRender` are registered through `SubscriberInterface`; the AJAX `group` is the installed plugin folder, while the tables are chosen by the active shop. Joomla loads the plugin only when the shop imports its plugin group, and the plugin adds its CSS, JS, compare bar and modal only to site HTML pages on which it rendered a compare button (never to administrator pages, com_ajax responses or non-HTML documents).

No configuration required — table names and event handlers are selected automatically at runtime.

### Compatibility Test Scope

The CI uses the official Joomla Docker images (newest Joomla 5.4.x and 6.x, printed as `Tested versions: …` in each job log) plus real J2Commerce/J2Store runtimes and verifies the AJAX endpoint, product data query, stock labels, and asset registration paths for Joomla 5/J2Commerce 4 and Joomla 6/J2Commerce 6. The `onAfterRender` injection path is now exercised against a real Joomla `HtmlDocument`: the suite asserts that the compare **bar** and **modal** markup (rendered from the real `tmpl/` layouts) is injected before `</body>`. The storefront events are also dispatched for real — the J2Commerce 6 per-item/detail hooks go through a real `Joomla\Event\Dispatcher` after the plugin is registered as a subscriber, and the J2Store 4 events are fired as J2Store 4.1.4 fires them (generic event, result read from the `result` argument) — and the suite asserts the rendered compare button (with the seeded product id) is emitted on both stacks. These are real end-to-end proofs of the render and event-dispatch paths, not keyword or file-existence checks.

## Installation
1. Download `plg_j2commerce_productcompare_<version>.zip` from the latest release
2. **System → Install → Extensions**
3. Upload and install
4. Enable via **System → Manage → Plugins**

## Updating

The manifest registers this repository's `updates/update.xml` as update server (`<updateservers>`), and the install script makes sure the update site is present after every install or update. New versions appear under **System → Update → Extensions**. You can also install a newer ZIP over the existing installation.

## Uninstall

Uninstall via **System → Manage → Extensions**. The plugin creates no database tables. On Joomla 5 the uninstall script removes both `plugins/j2commerce/productcompare/` and the `plugins/j2store/productcompare/` mirror; on Joomla 6 Joomla removes `plugins/j2commerce/productcompare/`. Media and language files are removed by Joomla. Template overrides in your template folder are not removed.

## Configuration

**System → Manage → Plugins → J2Commerce - Product Compare**

- **Show in Product List:** Display button in lists (Default: Yes)
- **Show in Product Detail:** Display on detail pages (Default: Yes)
- **Maximum Products:** Max products to compare (Default: 4, Range: 2-10)
- **Button Text:** Custom button text (Default: "Compare")
- **Button CSS Class:** CSS classes (Default: `btn btn-secondary`)

## Usage

1. Browse products
2. Click "Compare" button
3. Products added to comparison bar
4. Click "View Comparison" to see modal
5. Compare attributes side-by-side

## Development

### Structure
```
plg_j2commerce_productcompare/
├── README.md
├── VERSION
├── LICENSE.txt
├── plg_j2commerce_productcompare.xml   # Joomla manifest (group="j2commerce", element="productcompare")
├── build.sh
├── script.php                          # Install/update/uninstall script
├── services/provider.php
├── src/Extension/ProductCompare.php
├── tmpl/ (bar, button, modal, table)    # Layouts, overridable
├── language/ (en-GB, de-DE, fr-FR)
├── media/ (js, css)                    # Installed to media/plg_j2commerce_productcompare/
└── tests/
```

Installed path: `plugins/j2commerce/productcompare/` (Joomla 6) or `plugins/j2store/productcompare/` (Joomla 5 mirror)

### Building
```bash
./build.sh
```

## Automated Testing

This plugin has automated tests that run via GitHub Actions (`j2commerce-product-compare.yml`) on pushes and pull requests to `main` that change this directory, `shared/**` or the workflow file. CI also runs a PHP syntax check and the language file lint. Details: [testing.md](../../.claude/skills/joomla-extensions/references/testing.md).

### Test Suites

1. **Installation** — plugin registration in DB, file deployment
2. **Configuration** — plugin params, language files, and real XML manifest parameter parsing (defaults for `show_in_list`, `show_in_detail`, `max_products`, `button_text`, `button_class`)
3. **Media Files** — CSS/JS deployment and structural validation (asset.json registers the expected script/style assets; JS consumes the `plg_j2commerce_productcompare` script options and binds the compare selectors)
4. **Plugin Class** — method existence, `SubscriberInterface`, `isJ2Commerce6()` detection
5. **AJAX Endpoint** — HTTP tests against com_ajax (J2Commerce 4 and 6 group)
6. **getProductsData** — DB query compatibility for J2Commerce 4 and 6 table schemas
7. **Asset Injection** — WebAssetManager + script-options registration driven against a real `HtmlDocument` after a button was rendered through the storefront event; nothing is added without a button, for com_ajax requests or for a JSON document
8. **Render Injection** — `onAfterRender()` injects the compare bar + modal markup into a real HTML `<body>` before `</body>` once a button was rendered; a page without a button stays unchanged (both stacks)
9. **Event Dispatch** — real product rows are seeded and the storefront events are driven, asserting the compare button is emitted: J2Commerce 6 (`onJ2CommerceAfterProductListItemDisplay`, `onJ2CommerceAfterProductDisplay`) through a real dispatcher, and J2Store 4 (`onJ2StoreAfterAddToCartButton` in the list context, `onJ2StoreAfterProductDisplay`) as generic events the way J2Store 4.1.4 fires them; also asserts that the add-to-cart hook of the detail page adds no second button and that the J2Store events add nothing on J2Commerce 6
10. **Active Shop Detection** — seeds `#__j2commerce_*` and `#__j2store_*` with the same product IDs but different data, switches `com_j2commerce`/`com_j2store` in `#__extensions` and asserts over the real AJAX endpoint that the enabled shop's data is returned (J6: J2Commerce 6 active, both active, J2Store active, none active; J5: J2Store active, both active); restores all component states and drops only the tables it created
11. **Page Render** — real HTTP requests: on Joomla 6 a visible J2Commerce 6 product page contains the plugin CSS/JS, its script options, the compare button and the bar and modal before `</body>`; on both stacks the home page and a com_ajax response contain none of it. (A J2Store 4 product page is not requested: it needs the complete J2Store catalogue setup; the J2Store hooks are covered by Event Dispatch.)
12. **Installer Messages** — shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
13. **Uninstall** — clean removal from database and filesystem

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`; for Joomla 6 also `tests/j2commerce6.zip`, built from the J2Commerce 6 commit pinned in the workflow (`7edb6e11ae9148bf996b06c47a0d8266865af7b2`). Full commands: [Local Prerequisites](../../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# in j2commerce/plg_j2commerce_productcompare
./build.sh
cp *.zip tests/extension.zip

cd tests
docker compose up -d
timeout 300 bash -c 'until docker exec plg_j2commerce_productcompare_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# Joomla 6 (requires tests/j2commerce6.zip)
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec plg_j2commerce_productcompare_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_j2commerce_productcompare_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

CI sets `TEST_STRICT_SKIP=1` (a test that would SKIP fails); prefix the command with it to reproduce CI.

## Troubleshooting

### Compare Button Not Showing
**Problem:** Button missing on product pages  
**Solution:**
1. Verify plugin is enabled in **System → Manage → Plugins**
2. Check "Show in Product List" and "Show in Product Detail" settings
3. Verify J2Commerce template includes plugin positions
4. Clear Joomla cache

### Comparison Bar Not Appearing
**Problem:** Products added but bar not visible  
**Solution:**
1. Check browser console for JavaScript errors
2. Verify media files loaded (CSS/JS)
3. Check for CSS conflicts with template
4. Ensure the browser allows `localStorage` (not disabled/private mode)

### Modal Not Opening
**Problem:** Click "View Comparison" but nothing happens  
**Solution:**
1. Check browser console for errors
2. Verify `media/plg_j2commerce_productcompare/js/productcompare.js` is loaded
3. Test in different browser
4. Disable conflicting JavaScript plugins

### Products Not Persisting
**Problem:** Comparison list clears on page reload  
**Solution:**
The selection is stored in the browser's `localStorage`, not in the PHP session.
1. Ensure the browser allows `localStorage` (not disabled/private mode)
2. Check whether the browser clears site data on reload or exit
3. Note that the selection is kept per browser and per site address

### Maximum Products Not Enforced
**Problem:** Can add more than configured maximum  
**Solution:**
1. Clear browser cache
2. Verify plugin configuration saved
3. Check JavaScript console for errors
4. Re-save plugin settings

## Template Overrides

All HTML rendered by this plugin can be overridden from your active Joomla template without modifying the plugin files. Overrides survive plugin updates.

### How it works

The plugin uses `Joomla\CMS\Layout\FileLayout` with the following resolution order (first match wins):

1. `templates/{your-template}/html/plg_j2commerce_productcompare/{layout}.php`
2. `plugins/j2commerce/productcompare/tmpl/{layout}.php` ← plugin default (Joomla 6)
3. `plugins/j2store/productcompare/tmpl/{layout}.php` ← plugin default (Joomla 5 mirror)

### Available layouts

| File | What it renders |
|------|----------------|
| `button.php` | The "Compare" button shown on product list and detail pages |
| `bar.php` | The fixed bar at the bottom of the page showing selected products |
| `modal.php` | The modal dialog container (content loaded via AJAX) |
| `table.php` | The comparison table returned by the AJAX endpoint |

### Creating an override

1. Create the override directory in your template:
   ```
   templates/{your-template}/html/plg_j2commerce_productcompare/
   ```

2. Copy the layout file(s) you want to override from the plugin:
   ```
   plugins/j2commerce/productcompare/tmpl/button.php  →  templates/{your-template}/html/plg_j2commerce_productcompare/button.php
   plugins/j2commerce/productcompare/tmpl/table.php   →  templates/{your-template}/html/plg_j2commerce_productcompare/table.php
   ```
   You only need to copy the files you actually want to change.

3. Edit the copy in your template directory.

### Variables available in each layout

**`button.php`**
```php
$productId   // (int)    J2Store product ID
$buttonText  // (string) Translated button label (from plugin params)
$buttonClass // (string) CSS classes (from plugin params, default: "btn btn-secondary")
```

**`bar.php`**

No PHP variables — all text is rendered via `Text::_()` language keys directly in the layout.

**`modal.php`**

No PHP variables — the modal body is populated via AJAX after the user clicks "View Comparison".

**`table.php`**
```php
$products  // (array) Array of product objects, each with:
           //   ->title      (string) Product/article title
           //   ->sku        (string) Variant SKU
           //   ->price      (float)  Variant price
           //   ->stock      (int)    Stock quantity
           //   ->introtext  (string) Raw HTML from Joomla article intro text
           //   ->options    (array)  Product options as [['option_name' => ..., 'option_value' => ...], ...]
```

### Example: adding a custom row to the comparison table

Copy `tmpl/table.php` to your template override directory and add a row:

```php
// After the existing stock row:
<tr>
    <th scope="row"><?php echo Text::_('YOUR_CUSTOM_ATTRIBUTE'); ?></th>
    <?php foreach ($products as $product) : ?>
        <td>
            <?php foreach ($product->options as $opt) : ?>
                <?php echo $this->escape($opt['option_name'] . ': ' . $opt['option_value']); ?><br>
            <?php endforeach; ?>
        </td>
    <?php endforeach; ?>
</tr>
```

### Example: replacing the button with a custom icon button

In your `button.php` override:

```php
<?php defined('_JEXEC') or die; ?>
<button type="button"
        class="btn btn-outline-secondary btn-sm j2store-compare-btn"
        data-product-id="<?php echo (int) $productId; ?>"
        title="<?php echo $this->escape($buttonText); ?>">
    <span class="icon-random" aria-hidden="true"></span>
    <span class="visually-hidden"><?php echo $this->escape($buttonText); ?></span>
</button>
```

### CSS customization

The plugin loads `media/plg_j2commerce_productcompare/css/productcompare.css` via Joomla's WebAssetManager. To override styles, add CSS to your template's stylesheet — the plugin CSS uses mostly non-`!important` rules so template styles take precedence naturally.

Key CSS classes:

| Class | Element |
|-------|---------|
| `.j2store-compare-btn` | Compare button (all instances) |
| `.j2store-compare-btn.active` | Button when product is in comparison list |
| `.j2store-compare-bar` | Fixed bottom bar |
| `.compare-bar-products` | Product thumbnails area inside the bar |
| `.j2store-compare-modal` | Modal overlay container |
| `.j2store-comparison-table` | Comparison table inside the modal |

## Configuration Examples

### Minimal Comparison (2 Products)
```
Show in Product List: Yes
Show in Product Detail: Yes
Maximum Products: 2
Button Text: Compare
Button CSS Class: btn btn-sm btn-outline-primary
```

### Standard Comparison (4 Products)
```
Show in Product List: Yes
Show in Product Detail: Yes
Maximum Products: 4
Button Text: Compare
Button CSS Class: btn btn-secondary
```

### Extended Comparison (6 Products)
```
Show in Product List: Yes
Show in Product Detail: Yes
Maximum Products: 6
Button Text: Add to Compare
Button CSS Class: btn btn-primary
```

## Compared Attributes

The comparison table displays:
- Product name (column header)
- SKU
- Price
- Stock status (in stock / out of stock)
- Short description (article intro text, first 200 characters)

Additional rows can be added via a `table.php` template override.

## Browser Compatibility

### Tested Browsers
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile Safari (iOS 14+)
- Chrome Mobile (Android 10+)

### Required Features
- JavaScript enabled
- `localStorage`
- CSS3 support
- AJAX/Fetch API

## Performance Considerations

- **Browser storage:** only the selected product IDs are stored in `localStorage`
- **AJAX calls:** none when adding or removing products; opening the comparison issues one AJAX request that loads product data from the database
- **Page load impact:** ~50KB (CSS + JS)
- **Database queries:** only when the comparison is opened (product data and options of the selected products)

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)**

Users can add additional language files by creating new language folders following Joomla's language structure:
```
language/{language-tag}/plg_j2commerce_productcompare.ini
language/{language-tag}/plg_j2commerce_productcompare.sys.ini
```

## Accessibility

- Keyboard navigation supported
- ARIA labels for screen readers
- Focus indicators on interactive elements
- Semantic HTML structure
- High contrast mode compatible

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
