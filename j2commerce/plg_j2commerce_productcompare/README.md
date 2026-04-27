# J2Commerce Product Compare Plugin

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/j2commerce-product-compare.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/j2commerce-product-compare.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-productcompare.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-productcompare.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Help customers make informed purchase decisions with side-by-side product comparison.

## Description

The J2Commerce Product Compare Plugin adds a visual comparison feature to your store. Customers can select multiple products and view them side-by-side in an elegant modal interface. A persistent comparison bar keeps track of selected products across pages. Fully responsive design works on all devices, with configurable maximum products and customizable styling to match your store's theme.

## Features

- Side-by-side product comparison
- Comparison bar with thumbnails
- Modal comparison view
- Add from list and detail pages
- Configurable maximum products (default: 4)
- Session-based storage
- Responsive design
- Customizable button styling
- AJAX operations

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.x or 6.x
- PHP 8.1 or higher
- J2Commerce 4.x or higher

## Installation
1. Download `plg_j2commerce_productcompare.zip`
2. **System → Extensions → Install**
3. Upload and install
4. Enable via **System → Plugins**
## Configuration

**System → Plugins → J2Commerce - Product Compare**

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
├── plg_j2commerce_productcompare.xml   # Joomla manifest (group="j2store", element="productcompare")
├── build.sh
├── services/provider.php
├── src/Extension/ProductCompare.php
├── language/ (en-GB, de-CH, fr-FR)
├── media/ (js, css)                    # Installed to media/plg_j2store_productcompare/
└── tests/
```

Installed path: `plugins/j2store/productcompare/`

### Building
```bash
./build.sh
```

## Automated Testing

This plugin has automated tests that run on every push via GitHub Actions.

### Test Suites

1. **Installation** - Plugin registration in DB, file deployment
2. **Configuration** - Plugin params, language files, XML manifest
3. **Media Files** - CSS/JS deployment and content validation
4. **Plugin Class** - Method existence and class structure
5. **AJAX Endpoint** - HTTP tests against com_ajax
6. **Uninstall** - Clean removal from database and filesystem

### Running Tests Locally

```bash
cd j2commerce/plg_j2commerce_productcompare/tests
docker compose up -d
# Wait for container readiness (health.txt written by docker-entrypoint.sh)
timeout 300 bash -c 'until docker exec plg_j2commerce_productcompare_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

## Troubleshooting

### Compare Button Not Showing
**Problem:** Button missing on product pages  
**Solution:**
1. Verify plugin is enabled in **System → Plugins**
2. Check "Show in Product List" and "Show in Product Detail" settings
3. Verify J2Commerce template includes plugin positions
4. Clear Joomla cache

### Comparison Bar Not Appearing
**Problem:** Products added but bar not visible  
**Solution:**
1. Check browser console for JavaScript errors
2. Verify media files loaded (CSS/JS)
3. Check for CSS conflicts with template
4. Ensure session storage enabled in browser

### Modal Not Opening
**Problem:** Click "View Comparison" but nothing happens  
**Solution:**
1. Check browser console for errors
2. Verify jQuery/Bootstrap loaded
3. Test in different browser
4. Disable conflicting JavaScript plugins

### Products Not Persisting
**Problem:** Comparison list clears on page reload  
**Solution:**
1. Verify PHP sessions working
2. Check session timeout settings
3. Test with cookies enabled
4. Verify AJAX endpoints responding

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

1. `templates/{your-template}/html/plg_j2store_productcompare/{layout}.php`
2. `plugins/j2store/productcompare/tmpl/{layout}.php` ← plugin default

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
   templates/{your-template}/html/plg_j2store_productcompare/
   ```

2. Copy the layout file(s) you want to override from the plugin:
   ```
   plugins/j2store/productcompare/tmpl/button.php  →  templates/{your-template}/html/plg_j2store_productcompare/button.php
   plugins/j2store/productcompare/tmpl/table.php   →  templates/{your-template}/html/plg_j2store_productcompare/table.php
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
        <td><?php echo $this->escape($product->options['your_option'] ?? '-'); ?></td>
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

The plugin loads `media/plg_j2store_productcompare/css/productcompare.css` via Joomla's WebAssetManager. To override styles, add CSS to your template's stylesheet — the plugin CSS uses non-`!important` rules so template styles take precedence naturally.

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
- Product image
- Product name
- SKU
- Price
- Stock status
- Short description
- Key specifications
- Add to cart button

Attributes are configurable via J2Commerce product settings.

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
- Session storage
- CSS3 support
- AJAX/Fetch API

## Performance Considerations

- **Session storage:** ~5KB per comparison list
- **AJAX calls:** 1 per add/remove action
- **Page load impact:** ~50KB (CSS + JS)
- **Database queries:** 0 additional (session-based)

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-CH)** - Swiss German
- **French (fr-FR)** - French

Users can add additional language files by creating new language folders following Joomla's language structure:
```
language/{language-tag}/plg_j2store_productcompare.ini
language/{language-tag}/plg_j2store_productcompare.sys.ini
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
