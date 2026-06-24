# J2Commerce Import/Export Component

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/j2commerce-import-export.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/j2commerce-import-export.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-importexport.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-importexport.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

Import and export J2Commerce products with all related Joomla data.

## Features

### Full Product Export/Import
Export and import complete products including:
- **Joomla Article** - Title, description, images, SEO metadata
- **Category** - With automatic creation if missing
- **J2Store Product** - Product type, tax profile, manufacturer
- **Variants** - SKU, price, weight, dimensions, stock
- **Tier Prices** - Quantity-based pricing, customer groups
- **Product Images** - Main, thumbnail, additional images
- **Options** - Product options with values and price modifiers
- **Filters** - Filter groups and values
- **Tags** - Article tags
- **Custom Fields** - Joomla custom field values
- **Menu Items** - Optional, with configurable access levels
- **Metafields** - J2Store metafield data

### Duplicate Detection
Products are matched and updated (instead of duplicated) using three methods:
1. **Article ID** - Exact match if provided in import
2. **Alias** - URL-safe article alias
3. **SKU** - Product variant SKU

### Import Options
- Update existing products or create new only
- Create menu items automatically
- Configure menu type and access level
- Set default category for new products
- Import without stock management (unlimited inventory)
- **Quantity Update Mode** - Choose how stock quantities are updated:
  - **Replace**: Overwrite existing stock with imported value (default)
  - **Add**: Add imported quantity to existing stock (useful for restocking)

### Supported Formats
- **JSON** - Recommended for full export, includes field documentation
- **CSV** - Excel-compatible with UTF-8 BOM, comment lines supported
- **XML** - With field documentation section

## Requirements

- Joomla 5.x or 6.x
- PHP 8.1 or higher
- J2Commerce 4.x (`#__j2store_*` tables) or J2Commerce 6.x (`#__j2commerce_*` tables)

### J2Commerce Version Compatibility

All models use `J2CommerceAwareTrait` for runtime version detection. The trait checks for `#__j2commerce_products` in the database to determine whether J2Commerce 6 is installed:

- **J2Commerce 4.x** — tables prefixed `#__j2store_*`, primary key columns named `j2store_*_id`
- **J2Commerce 6.x** — tables prefixed `#__j2commerce_*`, primary key columns named `j2commerce_*_id`

The `t('suffix')` helper returns the correct table name and `col('j2store_col')` returns the correct column name for the detected version. No configuration required — detection is automatic.

## Installation

1. Download `com_j2commerce_importexport_1.0.0.zip`
2. **System → Extensions → Install**
3. Upload and install
4. Access via **Components → J2Commerce Import/Export**

## Configuration

**Components → J2Commerce Import/Export → Options**

| Setting | Default | Description |
|---------|---------|-------------|
| Batch Size | 100 | Records per batch (10-1000) |
| Default Format | JSON | Export format |
| CSV Delimiter | ; | Field separator for CSV |
| CSV Enclosure | " | Text qualifier for CSV |

## Usage

### Full Product Export
1. Select **Products (Complete)** as export type
2. Choose JSON format
3. Click **Export Data**

The export includes all product data in a single JSON file that can be re-imported.

### Full Product Import
1. Select **Products (Complete)** as import type
2. Upload the JSON or CSV file
3. Configure options:
   - **Update existing products** - Update if article_id, alias, or SKU matches
   - **Create menu items** - Auto-create menu entries
   - **Menu type** - Target menu for new items
   - **Access level** - Visibility of menu items
   - **Quantity Update Mode** - Replace or add to existing stock
4. Click **Preview** to verify data
5. Click **Start Import**

### Quantity Update Mode
When importing products with stock quantities, you can choose how the quantity is updated:

- **Replace (default)**: The imported quantity overwrites the existing stock
  - Example: Existing stock = 50, Import quantity = 30 → Final stock = 30
  
- **Add**: The imported quantity is added to the existing stock
  - Example: Existing stock = 50, Import quantity = 30 → Final stock = 80
  - Useful for restocking scenarios where you want to add received inventory

### Image Import Workflow
Images must be uploaded to the server before import:

1. **Prepare images** - Resize, compress, and format images according to your design requirements
2. **Upload images** - Use FTP or Joomla Media Manager to upload to `images/products/`
3. **Reference in import** - Use relative paths like `images/products/my-product.jpg`

Example:
```
Server path: /var/www/html/images/products/phone.jpg
Import value: images/products/phone.jpg
```

### Simple Export/Import
For bulk updates of specific data:
- **Products (J2Store only)** - Basic product data
- **Variants** - SKU, prices, stock
- **Prices** - Tier pricing only
- **Categories** - Category structure

## Export Formats

### JSON Export Structure

JSON exports include a `_documentation` section with field descriptions:

```json
{
  "_documentation": {
    "description": "J2Commerce Product Export - Field Documentation",
    "import_notes": {
      "Images": "Upload images to server first, then reference the path.",
      "Duplicates": "Products matched by: 1) article_id, 2) alias, 3) SKU",
      "Stock": "Set manage_stock=0 to disable inventory tracking"
    },
    "fields": {
      "title": "Product/Article title (required)",
      "main_image": "Image path relative to Joomla root"
    }
  },
  "products": [
    {
      "title": "Product Name",
      "alias": "product-name",
      "introtext": "<p>Short description</p>",
      "fulltext": "<p>Full description</p>",
      "article_state": 1,
      "catid": 8,
      "category_title": "Products",
      "category_path": "products",
      "access": 1,
      "language": "*",
      "metadesc": "SEO description",
      "product_type": "simple",
      "enabled": 1,
      "taxprofile_id": 1,
      "variants": [
        {
          "sku": "PROD-001",
          "price": 99.00,
          "quantity": 50,
          "manage_stock": 1,
          "weight": 1.5,
          "tier_prices": []
        }
      ],
      "product_images": {
        "main_image": "images/products/product.jpg",
        "main_image_alt": "Product Name"
      },
      "tags": [
        {"title": "New", "alias": "new"}
      ]
    }
  ]
}
```

### CSV Export Structure

CSV exports include a comment line (starting with `#`) above the headers with field descriptions:

```csv
# Product title (required),URL-safe alias,Image path relative to Joomla root
title,alias,main_image
"My Product","my-product","images/products/product1.jpg"
"Other Product","other-product","images/products/product2.jpg"
```

**Note:** Comment lines (starting with `#`) are ignored during import.

### Key Fields Reference

| Field | Required | Description |
|-------|----------|-------------|
| `title` | Yes | Product/Article title |
| `alias` | No | URL alias (auto-generated from title if empty) |
| `sku` | No | Stock Keeping Unit (used for duplicate detection) |
| `price` | No | Product price (decimal) |
| `quantity` | No | Stock quantity |
| `manage_stock` | No | 1=track inventory, 0=unlimited stock |
| `main_image` | No | Path relative to Joomla root, e.g., `images/products/img.jpg` |
| `category_path` | No | Category path like `shop/electronics` (auto-creates if missing) |

## Development

### Structure
```
com_j2commerce_importexport/
├── administrator/
│   ├── components/com_j2commerce_importexport/
│   │   ├── services/provider.php
│   │   ├── src/
│   │   │   ├── Controller/
│   │   │   ├── Model/
│   │   │   │   ├── ExportModel.php
│   │   │   │   ├── ImportModel.php
│   │   │   │   └── J2CommerceAwareTrait.php   ← version detection + table helpers
│   │   │   └── View/Dashboard/
│   │   └── tmpl/dashboard/
│   └── language/ (en-GB, de-DE, fr-FR)
├── tests/
├── com_j2commerce_importexport.xml
└── VERSION
```

## Automated Testing

This component has automated tests that run on every push via GitHub Actions.

### Test Scope Note

The compatibility jobs install the extension ZIP into official Joomla Full Package images with real J2Commerce runtimes: Joomla 5.4.6 with J2Store/J2Commerce 4.1.4 and Joomla 6.1.1 with a J2Commerce 6 package built from the official `j2commerce/j2commerce` repository. Model tests seed and exercise the real `#__j2store_*` and `#__j2commerce_*` tables instead of stub schemas.

The export controller is now covered by a **real HTTP export test** (`07-export-http.php`): it authenticates against the Joomla administrator, obtains a valid CSRF token, and performs authenticated `task=export.export` requests for CSV and JSON. It asserts the HTTP status, the `Content-Type` and `Content-Disposition` (attachment + filename) headers, and that the downloaded file CONTENT contains the seeded product/variant data. Negative requests with a missing or invalid CSRF token are asserted to be rejected and to never leak seeded data. A companion **real HTTP import test** (`08-import-http.php`) performs a multipart upload to `task=import.upload`, runs `task=import.process`, asserts the product is actually created in the live `#__j2store_*` / `#__j2commerce_*` tables, and verifies the upload is rejected without a CSRF token. Both HTTP tests run on both stacks.

### Test Suites

1. **Installation** — component registration in DB, file deployment
2. **Component Structure** — `php -l` lint of every shipped PHP file + reflection-based class/type checks
3. **Export Model** — JSON, CSV, XML export output validation (J2Commerce 4 and 6)
4. **Import Model** — full product import, duplicate detection, quantity modes (J2Commerce 4 and 6)
5. **Export Controller** — `core.manage` access check and structure (reflection)
6. **Export HTTP (CSRF)** — real authenticated HTTP CSV/JSON export with header + content assertions and CSRF rejection (J2Commerce 4 and 6)
7. **Import HTTP (CSRF)** — real multipart upload + process creating a product in the DB, with CSRF rejection (J2Commerce 4 and 6)
8. **Uninstall** — clean removal from database and filesystem

### Running Tests Locally

```bash
cd tests
docker compose up -d
timeout 300 bash -c 'until docker exec com_j2commerce_importexport_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# Joomla 6
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec com_j2commerce_importexport_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

## Troubleshooting

### Import Fails with "Invalid file type"
**Problem:** Upload rejected immediately  
**Solution:** Only CSV, XML and JSON files are accepted. Verify the file extension and that the file is not corrupted.

### Import Stops Partway Through
**Problem:** Some rows imported, then error  
**Solution:** Check the error message for the row number. Common causes: missing required `title` field, invalid category path, or malformed image path. Fix the row and re-import — duplicate detection will skip already-imported products.

### Exported JSON is Empty
**Problem:** Export file contains no products  
**Solution:** Verify J2Commerce products exist and are published. Check that the selected export type matches your data (e.g., "Products (Complete)" requires J2Store product records, not just Joomla articles).

### Images Not Appearing After Import
**Problem:** Products imported but images missing  
**Solution:** Images must be uploaded to the server before import. Verify the path in the import file matches the actual file location relative to the Joomla root (e.g., `images/products/photo.jpg`).

### Duplicate Products Created
**Problem:** Re-importing creates new products instead of updating  
**Solution:** Enable "Update existing products" in import options. Ensure the import file contains `article_id`, `alias`, or `sku` values that match existing products.

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

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
