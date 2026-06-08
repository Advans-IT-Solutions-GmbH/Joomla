<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Application\ApplicationHelper;

class ImportModel extends BaseDatabaseModel
{
    use J2CommerceAwareTrait;

    const BATCH_SIZE = 100;

    /**
     * Returns the current user ID, or 0 in CLI/test contexts where no
     * application is booted (Factory::getApplication() would throw).
     * Catches \Exception only — PHP Errors (TypeError, ParseError) should
     * surface rather than be silently swallowed.
     */
    private function getCurrentUserId(): int
    {
        try {
            return (int) Factory::getApplication()->getIdentity()->id;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function importProductFull(array $data, array $options = []): array
    {
        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            // 1. Create/Update Category if needed
            $catId = $this->ensureCategory($data, $options);

            // 2. Create/Update Joomla Article
            $articleId = $this->importArticle($data, $catId, $options);

            // 3. Create/Update J2Store Product
            $productId = $this->importJ2StoreProduct($data, $articleId, $options);

            // 4. Import Variants
            $this->importVariants($data['variants'] ?? [], $productId, $options);

            // 5. Import Images
            $this->importProductImages($data['product_images'] ?? [], $productId);

            // 6. Import Options
            $this->importProductOptions($data['options'] ?? [], $productId);

            // 7. Import Filters
            $this->importProductFilters($data['filters'] ?? [], $productId);

            // 8. Import Files
            $this->importProductFiles($data['files'] ?? [], $productId);

            // 9. Import Tags
            $this->importArticleTags($data['tags'] ?? [], $articleId);

            // 10. Import Custom Fields
            $this->importCustomFields($data['custom_fields'] ?? [], $articleId);

            // 11. Import Metafields (J2Commerce 6 only)
            $this->importProductMetafields($data['metafields'] ?? [], $productId);

            // 12. Create Menu Item if requested
            if (!empty($options['create_menu']) && !empty($data['menu_item'])) {
                $this->importMenuItem($data['menu_item'], $articleId, $options);
            }

            $db->transactionCommit();
            return ['success' => true, 'article_id' => $articleId, 'product_id' => $productId];

        } catch (\Exception $e) {
            $db->transactionRollback();
            throw $e;
        }
    }

    protected function ensureCategory(array $data, array $options): int
    {
        if (!empty($data['catid'])) {
            return (int) $data['catid'];
        }

        if (empty($data['category_path'])) {
            return $options['default_category'] ?? 2; // Uncategorised
        }

        $db = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select('id')
            ->from($db->quoteName('#__categories'))
            ->where('path = :path')
            ->where('extension = ' . $db->quote('com_content'))
            ->bind(':path', $data['category_path']);

        $db->setQuery($query);
        $catId = $db->loadResult();

        if ($catId) {
            return (int) $catId;
        }

        // Create category
        return $this->createCategory($data['category_title'] ?? 'Imported', $data['category_alias'] ?? null);
    }

    protected function createCategory(string $title, ?string $alias = null): int
    {
        $db = $this->getDatabase();
        $alias = $alias ?: ApplicationHelper::stringURLSafe($title);

        $category = (object) [
            'parent_id' => 1,
            'level' => 1,
            'path' => $alias,
            'extension' => 'com_content',
            'title' => $title,
            'alias' => $alias,
            'published' => 1,
            'access' => 1,
            'language' => '*',
            'created_user_id' => $this->getCurrentUserId(),
            'created_time' => Factory::getDate()->toSql(),
        ];

        $db->insertObject('#__categories', $category);
        $catId = $db->insertid();

        // Rebuild nested set
        Table::getInstance('Category')->rebuild();

        return $catId;
    }

    protected function importArticle(array $data, int $catId, array $options): int
    {
        $db = $this->getDatabase();
        $userId = $this->getCurrentUserId();
        $now = Factory::getDate()->toSql();

        // Check if article exists - try multiple methods
        $existingId = null;
        
        // 1. Check by article_id if provided
        if (!empty($data['article_id'])) {
            $query = $this->createDbQuery($db)
                ->select('id')
                ->from($db->quoteName('#__content'))
                ->where('id = :id')
                ->bind(':id', $data['article_id'], \Joomla\Database\ParameterType::INTEGER);
            $db->setQuery($query);
            $existingId = $db->loadResult();
        }
        
        // 2. Check by alias if not found by ID
        $alias = $data['alias'] ?? ApplicationHelper::stringURLSafe($data['title']);
        if (!$existingId && !empty($alias)) {
            $query = $this->createDbQuery($db)
                ->select('id')
                ->from($db->quoteName('#__content'))
                ->where('alias = :alias')
                ->bind(':alias', $alias);
            $db->setQuery($query);
            $existingId = $db->loadResult();
        }
        
        // 3. Check by SKU via J2Store product -> article link
        if (!$existingId && !empty($data['variants'][0]['sku'])) {
            $sku = $data['variants'][0]['sku'];
            $query = $this->createDbQuery($db)
                ->select('p.product_source_id')
                ->from($db->quoteName($this->t('products'), 'p'))
                ->join('INNER', $db->quoteName($this->t('variants'), 'v') . ' ON p.' . $this->col('j2store_product_id') . ' = v.product_id')
                ->where('v.sku = :sku')
                ->where('p.product_source = ' . $db->quote('com_content'))
                ->bind(':sku', $sku);
            $db->setQuery($query);
            $existingId = $db->loadResult();
        }

        $article = (object) [
            'title' => $data['title'],
            'alias' => $alias,
            'introtext' => $data['introtext'] ?? '',
            'fulltext' => $data['fulltext'] ?? '',
            'state' => $data['article_state'] ?? 1,
            'catid' => $catId,
            'access' => $data['access'] ?? 1,
            'language' => $data['language'] ?? '*',
            'featured' => $data['featured'] ?? 0,
            'images' => $data['article_images'] ?? '{}',
            'urls' => $data['urls'] ?? '{}',
            'attribs' => $data['attribs'] ?? '{}',
            'metakey' => $data['metakey'] ?? '',
            'metadesc' => $data['metadesc'] ?? '',
            'metadata' => $data['metadata'] ?? '{}',
            'publish_up' => $data['publish_up'] ?? $now,
            'publish_down' => $data['publish_down'] ?? null,
            'modified' => $now,
            'modified_by' => $userId,
        ];

        if ($existingId && ($options['update_existing'] ?? true)) {
            $article->id = $existingId;
            $db->updateObject('#__content', $article, 'id');
            return $existingId;
        }

        $article->created = $now;
        $article->created_by = $userId;
        $db->insertObject('#__content', $article);
        return $db->insertid();
    }

    protected function importJ2StoreProduct(array $data, int $articleId, array $options): int
    {
        $db = $this->getDatabase();
        $userId = $this->getCurrentUserId();
        $now = Factory::getDate()->toSql();

        // Check if product exists
        $query = $this->createDbQuery($db)
            ->select($this->col('j2store_product_id'))
            ->from($db->quoteName($this->t('products')))
            ->where('product_source_id = :articleid')
            ->where('product_source = ' . $db->quote('com_content'))
            ->bind(':articleid', $articleId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        $existingId = $db->loadResult();

        $product = (object) [
            'product_source' => 'com_content',
            'product_source_id' => $articleId,
            'product_type' => $data['product_type'] ?? 'simple',
            'visibility' => $data['visibility'] ?? 1,
            'enabled' => $data['enabled'] ?? 1,
            'taxprofile_id' => $data['taxprofile_id'] ?? 0,
            'manufacturer_id' => $data['manufacturer_id'] ?? 0,
            'vendor_id' => $data['vendor_id'] ?? 0,
            'has_options' => $data['has_options'] ?? 0,
            'addtocart_text' => $data['addtocart_text'] ?? '',
            'params' => $data['params'] ?? '{}',
            'plugins' => $data['plugins'] ?? '',
            'up_sells' => $data['up_sells'] ?? '',
            'cross_sells' => $data['cross_sells'] ?? '',
            'main_tag' => $data['main_tag'] ?? '',
            'modified_on' => $now,
            'modified_by' => $userId,
        ];

        if ($existingId && ($options['update_existing'] ?? true)) {
            $product->{$this->col('j2store_product_id')} = $existingId;
            $db->updateObject($this->t('products'), $product, $this->col('j2store_product_id'));
            return $existingId;
        }

        $product->created_on = $now;
        $product->created_by = $userId;
        $db->insertObject($this->t('products'), $product);
        return $db->insertid();
    }

    protected function importVariants(array $variants, int $productId, array $options): void
    {
        $db = $this->getDatabase();
        $userId = $this->getCurrentUserId();
        $now = Factory::getDate()->toSql();

        foreach ($variants as $variantData) {
            $sku = $variantData['sku'] ?? null;
            $existingId = null;

            if ($sku) {
                $query = $this->createDbQuery($db)
                    ->select($this->col('j2store_variant_id'))
                    ->from($db->quoteName($this->t('variants')))
                    ->where('sku = :sku')
                    ->where('product_id = :productid')
                    ->bind(':sku', $sku)
                    ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
                $db->setQuery($query);
                $existingId = $db->loadResult();
            }

            $variant = (object) [
                'product_id' => $productId,
                'is_master' => $variantData['is_master'] ?? 1,
                'sku' => $sku,
                'upc' => $variantData['upc'] ?? '',
                'price' => $variantData['price'] ?? 0,
                'pricing_calculator' => $variantData['pricing_calculator'] ?? 'standard',
                'shipping' => $variantData['shipping'] ?? 1,
                'weight' => $variantData['weight'] ?? 0,
                'weight_class_id' => $variantData['weight_class_id'] ?? 1,
                'length' => $variantData['length'] ?? 0,
                'width' => $variantData['width'] ?? 0,
                'height' => $variantData['height'] ?? 0,
                'length_class_id' => $variantData['length_class_id'] ?? 1,
                'manage_stock' => $variantData['manage_stock'] ?? 0,
                'quantity_restriction' => $variantData['quantity_restriction'] ?? 0,
                'min_sale_qty' => $variantData['min_sale_qty'] ?? 0,
                'max_sale_qty' => $variantData['max_sale_qty'] ?? 0,
                'notify_qty' => $variantData['notify_qty'] ?? 0,
                'availability' => $variantData['availability'] ?? 1,
                'allow_backorder' => $variantData['allow_backorder'] ?? 0,
                'params' => $variantData['params'] ?? '{}',
                'modified_on' => $now,
                'modified_by' => $userId,
            ];

            if ($existingId && ($options['update_existing'] ?? true)) {
                $variant->{$this->col('j2store_variant_id')} = $existingId;
                $db->updateObject($this->t('variants'), $variant, $this->col('j2store_variant_id'));
                $variantId = $existingId;
            } else {
                $variant->created_on = $now;
                $variant->created_by = $userId;
                $db->insertObject($this->t('variants'), $variant);
                $variantId = $db->insertid();
            }

            // Import quantity
            $this->importVariantQuantity($variantId, $variantData, $options);

            // Import tier prices
            if (!empty($variantData['tier_prices'])) {
                $this->importTierPrices($variantId, $variantData['tier_prices']);
            }
        }
    }

    /**
     * Import variant quantity with support for different update modes
     *
     * @param int   $variantId Variant ID
     * @param array $data      Variant data including quantity
     * @param array $options   Import options including quantity_mode (replace|add)
     */
    protected function importVariantQuantity(int $variantId, array $data, array $options = []): void
    {
        $db = $this->getDatabase();
        $quantityMode = $options['quantity_mode'] ?? 'replace';

        $query = $this->createDbQuery($db)
            ->select([$this->col('j2store_productquantity_id'), 'quantity'])
            ->from($db->quoteName($this->t('productquantities')))
            ->where('variant_id = :variantid')
            ->bind(':variantid', $variantId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $existing = $db->loadObject();

        $importQuantity = (int) ($data['quantity'] ?? 0);

        // Calculate final quantity based on mode
        if ($existing && $quantityMode === 'add') {
            // Add mode: add imported quantity to existing stock
            $finalQuantity = (int) $existing->quantity + $importQuantity;
        } else {
            // Replace mode (default): overwrite with imported quantity
            $finalQuantity = $importQuantity;
        }

        $qty = (object) [
            'variant_id'         => $variantId,
            'product_attributes' => $data['product_attributes'] ?? '',
            'quantity'           => $finalQuantity,
            'on_hold'            => $data['on_hold'] ?? 0,
            'sold'               => $data['qty_sold'] ?? 0,
        ];

        if ($existing) {
            $qty->{$this->col('j2store_productquantity_id')} = $existing->{$this->col('j2store_productquantity_id')};
            $db->updateObject($this->t('productquantities'), $qty, $this->col('j2store_productquantity_id'));
        } else {
            $db->insertObject($this->t('productquantities'), $qty);
        }
    }

    protected function importTierPrices(int $variantId, array $prices): void
    {
        $db = $this->getDatabase();

        // Delete existing tier prices
        $query = $this->createDbQuery($db)
            ->delete($db->quoteName($this->t('product_prices')))
            ->where('variant_id = :variantid')
            ->bind(':variantid', $variantId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        foreach ($prices as $priceData) {
            $price = (object) [
                'variant_id' => $variantId,
                'quantity_from' => $priceData['quantity_from'] ?? 0,
                'quantity_to' => $priceData['quantity_to'] ?? 0,
                'date_from' => $priceData['date_from'] ?? null,
                'date_to' => $priceData['date_to'] ?? null,
                'customer_group_id' => $priceData['customer_group_id'] ?? 0,
                'price' => $priceData['price'] ?? 0,
            ];
            $db->insertObject($this->t('product_prices'), $price);
        }
    }

    protected function importProductImages(array $images, int $productId): void
    {
        if (empty($images)) return;

        $db = $this->getDatabase();

        $query = $this->createDbQuery($db)
            ->delete($db->quoteName($this->t('productimages')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        // Process additional images (ensure it's an array)
        $additionalImages = $images['additional_images'] ?? [];
        if (is_string($additionalImages)) {
            $additionalImages = json_decode($additionalImages, true) ?? [];
        }

        $img = (object) [
            'product_id' => $productId,
            'main_image' => $images['main_image'] ?? '',
            'main_image_alt' => $images['main_image_alt'] ?? '',
            'thumb_image' => $images['thumb_image'] ?? '',
            'thumb_image_alt' => $images['thumb_image_alt'] ?? '',
            'additional_images' => is_array($additionalImages) ? json_encode($additionalImages) : $additionalImages,
            'additional_images_alt' => $images['additional_images_alt'] ?? '[]',
        ];
        $db->insertObject($this->t('productimages'), $img);
    }

    protected function importProductOptions(array $options, int $productId): void
    {
        if (empty($options)) {
            return;
        }

        $db = $this->getDatabase();

        // Remove existing product_optionvalues rows before deleting product_options,
        // because product_optionvalues.productoption_id references product_options.
        $existingProductOptionIds = [];
        $query = $this->createDbQuery()
            ->select($this->col('j2store_productoption_id'))
            ->from($db->quoteName($this->t('product_options')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $existingProductOptionIds = $db->loadColumn() ?: [];

        foreach ($existingProductOptionIds as $poId) {
            $poId = (int) $poId;
            $query = $this->createDbQuery()
                ->delete($db->quoteName($this->t('product_optionvalues')))
                ->where('productoption_id = :poid')
                ->bind(':poid', $poId, \Joomla\Database\ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();
        }

        $query = $this->createDbQuery()
            ->delete($db->quoteName($this->t('product_options')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        foreach ($options as $ordering => $optionData) {
            $optionId = $this->ensureOption($optionData);

            $po = (object) [
                'option_id'  => $optionId,
                'parent_id'  => (int) ($optionData['parent_id'] ?? 0),
                'product_id' => $productId,
                'ordering'   => (int) ($optionData['ordering'] ?? $ordering),
                'required'   => (int) ($optionData['required'] ?? 0),
                'is_variant' => (int) ($optionData['is_variant'] ?? 0),
            ];
            $db->insertObject($this->t('product_options'), $po);
            $productOptionId = (int) $db->insertid();

            foreach ($optionData['values'] ?? [] as $valOrdering => $valueData) {
                $optionValueId = $this->ensureOptionValue($optionId, $valueData);

                $pov = (object) [
                    'productoption_id'                  => $productOptionId,
                    'optionvalue_id'                    => $optionValueId,
                    'parent_optionvalue'                => $valueData['parent_optionvalue'] ?? '',
                    'product_optionvalue_price'         => $valueData['product_optionvalue_price'] ?? '0.00000000',
                    'product_optionvalue_prefix'        => $valueData['product_optionvalue_prefix'] ?? '+',
                    'product_optionvalue_weight'        => $valueData['product_optionvalue_weight'] ?? '0.00000000',
                    'product_optionvalue_weight_prefix' => $valueData['product_optionvalue_weight_prefix'] ?? '+',
                    'product_optionvalue_sku'           => $valueData['product_optionvalue_sku'] ?? '',
                    'product_optionvalue_default'       => (int) ($valueData['product_optionvalue_default'] ?? 0),
                    'ordering'                          => (int) ($valueData['ordering'] ?? $valOrdering),
                    'product_optionvalue_attribs'       => $valueData['product_optionvalue_attribs'] ?? '',
                ];
                $db->insertObject($this->t('product_optionvalues'), $pov);
            }
        }
    }

    /**
     * Find or create an option definition by unique name.
     */
    protected function ensureOption(array $optionData): int
    {
        $db = $this->getDatabase();
        $uniqueName = $optionData['option_unique_name'] ?? '';

        if ($uniqueName) {
            $query = $this->createDbQuery()
                ->select($this->col('j2store_option_id'))
                ->from($db->quoteName($this->t('options')))
                ->where('option_unique_name = :uname')
                ->bind(':uname', $uniqueName);
            $db->setQuery($query);
            $optionId = $db->loadResult();

            if ($optionId) {
                return (int) $optionId;
            }
        }

        $now    = Factory::getDate()->toSql();
        $userId = $this->getCurrentUserId();

        $o = (object) [
            'type'              => $optionData['option_type'] ?? $optionData['type'] ?? 'select',
            'option_unique_name' => $uniqueName ?: ($optionData['option_name'] ?? ''),
            'option_name'       => $optionData['option_name'] ?? $uniqueName,
            'ordering'          => (int) ($optionData['ordering'] ?? 0),
            'enabled'           => 1,
            'option_params'     => $optionData['option_params'] ?? '',
            'access'            => (int) ($optionData['access'] ?? 1),
            'created_on'        => $now,
            'created_by'        => $userId,
            'modified_on'       => $now,
            'modified_by'       => $userId,
        ];
        $db->insertObject($this->t('options'), $o);

        return (int) $db->insertid();
    }

    /**
     * Find or create an option value definition by name within an option.
     */
    protected function ensureOptionValue(int $optionId, array $valueData): int
    {
        $db   = $this->getDatabase();
        $name = $valueData['optionvalue_name'] ?? '';

        if ($name) {
            $query = $this->createDbQuery()
                ->select($this->col('j2store_optionvalue_id'))
                ->from($db->quoteName($this->t('optionvalues')))
                ->where('option_id = :optionid')
                ->where('optionvalue_name = :name')
                ->bind(':optionid', $optionId, \Joomla\Database\ParameterType::INTEGER)
                ->bind(':name', $name);
            $db->setQuery($query);
            $valueId = $db->loadResult();

            if ($valueId) {
                return (int) $valueId;
            }
        }

        $ov = (object) [
            'option_id'        => $optionId,
            'optionvalue_name' => $name,
            'optionvalue_image' => $valueData['optionvalue_image'] ?? '',
            'ordering'         => (int) ($valueData['ordering'] ?? 0),
        ];
        $db->insertObject($this->t('optionvalues'), $ov);

        return (int) $db->insertid();
    }

    protected function importProductFilters(array $filters, int $productId): void
    {
        if (empty($filters)) return;

        $db = $this->getDatabase();

        $query = $this->createDbQuery($db)
            ->delete($db->quoteName($this->t('product_filters')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        foreach ($filters as $filter) {
            $filterId = $this->ensureFilter($filter);
            $pf = (object) ['product_id' => $productId, 'filter_id' => $filterId];
            $db->insertObject($this->t('product_filters'), $pf);
        }
    }

    protected function ensureFilter(array $filter): int
    {
        $db = $this->getDatabase();

        if (!empty($filter['filter_id'])) {
            return (int) $filter['filter_id'];
        }

        // Find or create filter group
        $groupId = $this->ensureFilterGroup($filter['filter_group_name'] ?? 'Default');

        // Find or create filter
        $query = $this->createDbQuery($db)
            ->select($this->col('j2store_filter_id'))
            ->from($db->quoteName($this->t('filters')))
            ->where('filter_name = :name')
            ->where('group_id = :groupid')
            ->bind(':name', $filter['filter_name'])
            ->bind(':groupid', $groupId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $filterId = $db->loadResult();

        if (!$filterId) {
            $f = (object) [
                'group_id' => $groupId,
                'filter_name' => $filter['filter_name'],
                'ordering' => 0,
            ];
            $db->insertObject($this->t('filters'), $f);
            $filterId = $db->insertid();
        }

        return $filterId;
    }

    protected function ensureFilterGroup(string $name): int
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery($db)
            ->select($this->col('j2store_filtergroup_id'))
            ->from($db->quoteName($this->t('filtergroups')))
            ->where('group_name = :name')
            ->bind(':name', $name);
        $db->setQuery($query);
        $groupId = $db->loadResult();

        if (!$groupId) {
            $g = (object) ['group_name' => $name, 'ordering' => 0, 'enabled' => 1];
            $db->insertObject($this->t('filtergroups'), $g);
            $groupId = $db->insertid();
        }

        return $groupId;
    }

    protected function importProductFiles(array $files, int $productId): void
    {
        if (empty($files)) return;

        $db = $this->getDatabase();

        foreach ($files as $file) {
            $f = (object) [
                'product_id'               => $productId,
                'product_file_display_name' => $file['product_file_display_name'] ?? '',
                'product_file_save_name'   => $file['product_file_save_name'] ?? '',
                'download_total'           => (int) ($file['download_total'] ?? 0),
            ];
            $db->insertObject($this->t('productfiles'), $f);
        }
    }

    protected function importArticleTags(array $tags, int $articleId): void
    {
        if (empty($tags)) return;

        $db = $this->getDatabase();

        // Delete existing tag mappings
        $query = $this->createDbQuery($db)
            ->delete($db->quoteName('#__contentitem_tag_map'))
            ->where('content_item_id = :articleid')
            ->where('type_alias = ' . $db->quote('com_content.article'))
            ->bind(':articleid', $articleId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        foreach ($tags as $tag) {
            $tagId = $this->ensureTag($tag);
            $map = (object) [
                'type_alias' => 'com_content.article',
                'core_content_id' => 0,
                'content_item_id' => $articleId,
                'tag_id' => $tagId,
                'tag_date' => Factory::getDate()->toSql(),
                'type_id' => 1,
            ];
            $db->insertObject('#__contentitem_tag_map', $map);
        }
    }

    protected function ensureTag(array $tag): int
    {
        $db = $this->getDatabase();

        if (!empty($tag['id'])) {
            return (int) $tag['id'];
        }

        $query = $this->createDbQuery($db)
            ->select('id')
            ->from($db->quoteName('#__tags'))
            ->where('title = :title')
            ->bind(':title', $tag['title']);
        $db->setQuery($query);
        $tagId = $db->loadResult();

        if (!$tagId) {
            $alias = $tag['alias'] ?? ApplicationHelper::stringURLSafe($tag['title']);
            $t = (object) [
                'parent_id' => 1,
                'level' => 1,
                'path' => $alias,
                'title' => $tag['title'],
                'alias' => $alias,
                'published' => 1,
                'access' => 1,
                'language' => '*',
            ];
            $db->insertObject('#__tags', $t);
            $tagId = $db->insertid();
        }

        return $tagId;
    }

    protected function importCustomFields(array $fields, int $articleId): void
    {
        if (empty($fields)) return;

        $db = $this->getDatabase();

        foreach ($fields as $field) {
            $fieldId = $this->getFieldIdByName($field['name']);
            if (!$fieldId) continue;

            $query = $this->createDbQuery($db)
                ->delete($db->quoteName('#__fields_values'))
                ->where('field_id = :fieldid')
                ->where('item_id = :itemid')
                ->bind(':fieldid', $fieldId, \Joomla\Database\ParameterType::INTEGER)
                ->bind(':itemid', $articleId, \Joomla\Database\ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $fv = (object) [
                'field_id' => $fieldId,
                'item_id' => $articleId,
                'value' => $field['value'],
            ];
            $db->insertObject('#__fields_values', $fv);
        }
    }

    /**
     * Import metafields for a product (J2Commerce 6 only).
     *
     * Replaces all existing metafields for the product, then re-inserts from
     * the import data. Silently skips on J2Commerce 4 (no metafields table).
     */
    protected function importProductMetafields(array $metafields, int $productId): void
    {
        if (empty($metafields) || !$this->isJ2Commerce6()) {
            return;
        }

        $db  = $this->getDatabase();
        $now = Factory::getDate()->toSql();

        $query = $this->createDbQuery()
            ->delete($db->quoteName($this->t('metafields')))
            ->where('owner_id = :productid')
            ->where('owner_resource = ' . $db->quote('product'))
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        foreach ($metafields as $mf) {
            $row = (object) [
                'metakey'        => $mf['metakey'] ?? '',
                'namespace'      => $mf['namespace'] ?? '',
                'scope'          => $mf['scope'] ?? '',
                'metavalue'      => $mf['metavalue'] ?? '',
                'valuetype'      => $mf['valuetype'] ?? 'string',
                'description'    => $mf['description'] ?? '',
                'owner_id'       => $productId,
                'owner_resource' => 'product',
                'created_at'     => $mf['created_at'] ?? $now,
                'updated_at'     => $now,
            ];
            $db->insertObject($this->t('metafields'), $row);
        }
    }

    protected function getFieldIdByName(string $name): ?int
    {
        $db = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select('id')
            ->from($db->quoteName('#__fields'))
            ->where('name = :name')
            ->where('context = ' . $db->quote('com_content.article'))
            ->bind(':name', $name);
        $db->setQuery($query);
        return $db->loadResult();
    }

    protected function importMenuItem(array $menuData, int $articleId, array $options): void
    {
        $db = $this->getDatabase();

        $menutype = $menuData['menutype'] ?? $options['default_menutype'] ?? 'mainmenu';
        $alias = $menuData['alias'] ?? ApplicationHelper::stringURLSafe($menuData['title']);

        $menu = (object) [
            'menutype' => $menutype,
            'title' => $menuData['title'],
            'alias' => $alias,
            'path' => $alias,
            'link' => 'index.php?option=com_content&view=article&id=' . $articleId,
            'type' => 'component',
            'published' => $menuData['published'] ?? ($options['menu_published'] ?? 1),
            'parent_id' => $menuData['parent_id'] ?? 1,
            'level' => 1,
            'component_id' => $this->getComponentId('com_content'),
            'access' => $menuData['access'] ?? ($options['menu_access'] ?? 1),
            'language' => $menuData['language'] ?? '*',
            'params' => $menuData['params'] ?? '{}',
            'client_id' => 0,
        ];

        $db->insertObject('#__menu', $menu);
        Table::getInstance('Menu')->rebuild();
    }

    protected function getComponentId(string $element): int
    {
        $db = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select('extension_id')
            ->from($db->quoteName('#__extensions'))
            ->where('element = :element')
            ->where('type = ' . $db->quote('component'))
            ->bind(':element', $element);
        $db->setQuery($query);
        return (int) $db->loadResult();
    }

    // Legacy methods for backward compatibility
    public function previewFile(string $filePath, int $limit = 10): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            
            // Handle JSON with _documentation wrapper
            if (isset($data['products'])) {
                $data = $data['products'];
            }
            
            return [
                'headers' => !empty($data[0]) ? array_keys($data[0]) : [],
                'rows' => array_slice($data, 0, $limit),
                'total' => count($data),
            ];
        }

        if ($ext === 'csv') {
            return $this->previewCSV($filePath, $limit);
        }

        return ['headers' => [], 'rows' => [], 'total' => 0];
    }

    /**
     * Preview CSV file, skipping comment lines
     */
    protected function previewCSV(string $filePath, int $limit = 10): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['headers' => [], 'rows' => [], 'total' => 0];
        }

        $headers = [];
        $rows = [];
        $total = 0;

        while (($line = fgetcsv($handle)) !== false) {
            // Skip empty lines
            if (empty($line) || (count($line) === 1 && empty($line[0]))) {
                continue;
            }
            
            // Skip comment lines (starting with #)
            if (isset($line[0]) && strpos(trim($line[0]), '#') === 0) {
                continue;
            }

            // First non-comment line is headers
            if (empty($headers)) {
                $headers = $line;
                continue;
            }

            $total++;
            if (count($rows) < $limit) {
                $rows[] = array_combine($headers, $line);
            }
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total' => $total,
        ];
    }

    /**
     * Parse CSV file into array, skipping comment lines
     */
    protected function parseCSV(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $headers = [];
        $data = [];

        while (($line = fgetcsv($handle)) !== false) {
            // Skip empty lines
            if (empty($line) || (count($line) === 1 && empty($line[0]))) {
                continue;
            }
            
            // Skip comment lines (starting with #)
            if (isset($line[0]) && strpos(trim($line[0]), '#') === 0) {
                continue;
            }

            // First non-comment line is headers
            if (empty($headers)) {
                $headers = $line;
                continue;
            }

            // Combine headers with values
            if (count($line) === count($headers)) {
                $data[] = array_combine($headers, $line);
            }
        }

        fclose($handle);

        return $data;
    }

    public function importData(string $filePath, string $type, array $mapping, array $options = []): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($ext === 'json') {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            
            // Handle JSON with _documentation wrapper
            if (isset($data['products'])) {
                $data = $data['products'];
            }
        } elseif ($ext === 'csv') {
            $data = $this->parseCSV($filePath);
        } else {
            throw new \RuntimeException('Unsupported file format: ' . $ext);
        }

        if (empty($data)) {
            return ['total' => 0, 'imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => ['No data found in file']];
        }

        $results = ['total' => count($data), 'imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($data as $index => $item) {
            try {
                if ($type === 'products_full') {
                    $this->importProductFull($item, $options);
                    $results['imported']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = 'Row ' . ($index + 1) . ': ' . $e->getMessage();
            }
        }

        return $results;
    }
}
