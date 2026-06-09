--
-- J2Commerce stub tables for integration tests.
--
-- Creates the minimal set of tables that the import/export models touch so
-- that round-trip tests run against a real schema instead of being skipped.
-- Column definitions are taken verbatim from the J2Commerce install SQL.
--

CREATE TABLE IF NOT EXISTS `#__j2commerce_products` (
  `j2commerce_product_id` int NOT NULL AUTO_INCREMENT,
  `visibility` int NOT NULL DEFAULT 1,
  `product_source` varchar(255) DEFAULT NULL,
  `product_source_id` int DEFAULT NULL,
  `product_type` varchar(255) DEFAULT NULL,
  `main_tag` varchar(255) DEFAULT NULL,
  `taxprofile_id` int DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `vendor_id` int DEFAULT NULL,
  `has_options` int DEFAULT NULL,
  `addtocart_text` varchar(255) NOT NULL DEFAULT '',
  `enabled` tinyint NOT NULL DEFAULT 1,
  `plugins` text,
  `params` text,
  `access` int UNSIGNED NOT NULL DEFAULT '0',
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `modified_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` int DEFAULT NULL,
  `up_sells` varchar(255) NOT NULL DEFAULT '',
  `cross_sells` varchar(255) NOT NULL DEFAULT '',
  `productfilter_ids` varchar(255) DEFAULT NULL,
  `hits` int unsigned NOT NULL DEFAULT '0',
  `checked_out` int UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `ordering` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`j2commerce_product_id`),
  UNIQUE KEY `catalogsource` (`product_source`,`product_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_variants` (
  `j2commerce_variant_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `is_master` int DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `upc` varchar(255) DEFAULT NULL,
  `price` decimal(15,5) DEFAULT NULL,
  `pricing_calculator` varchar(255) NOT NULL DEFAULT '',
  `shipping` int NOT NULL DEFAULT 0,
  `params` text,
  `length` decimal(15,5) DEFAULT NULL,
  `width` decimal(15,5) DEFAULT NULL,
  `height` decimal(15,5) DEFAULT NULL,
  `length_class_id` int DEFAULT NULL,
  `weight` decimal(15,5) DEFAULT NULL,
  `weight_class_id` int DEFAULT NULL,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `modified_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` int DEFAULT NULL,
  `manage_stock` int DEFAULT NULL,
  `quantity_restriction` int NOT NULL DEFAULT 0,
  `min_out_qty` decimal(15,5) DEFAULT NULL,
  `use_store_config_min_out_qty` int DEFAULT NULL,
  `min_sale_qty` decimal(15,5) DEFAULT NULL,
  `use_store_config_min_sale_qty` int DEFAULT NULL,
  `max_sale_qty` decimal(15,5) DEFAULT NULL,
  `use_store_config_max_sale_qty` int DEFAULT NULL,
  `notify_qty` decimal(15,5) DEFAULT NULL,
  `use_store_config_notify_qty` int DEFAULT NULL,
  `availability` int DEFAULT NULL,
  `sold` decimal(12,4) DEFAULT NULL,
  `allow_backorder` int NOT NULL DEFAULT 0,
  `isdefault_variant` int NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`j2commerce_variant_id`),
  KEY `variant_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_productquantities` (
  `j2commerce_productquantity_id` int NOT NULL AUTO_INCREMENT,
  `product_attributes` text NOT NULL COMMENT 'serialised variant attribute combination',
  `variant_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT 0,
  `on_hold` int NOT NULL DEFAULT 0,
  `sold` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`j2commerce_productquantity_id`),
  UNIQUE KEY `variantidx` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_product_prices` (
  `j2commerce_productprice_id` int NOT NULL AUTO_INCREMENT,
  `variant_id` int DEFAULT NULL,
  `quantity_from` decimal(15,5) DEFAULT NULL,
  `quantity_to` decimal(15,5) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `customer_group_id` int DEFAULT NULL,
  `price` decimal(15,5) DEFAULT NULL,
  `params` text,
  PRIMARY KEY (`j2commerce_productprice_id`),
  KEY `price_variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_productimages` (
  `j2commerce_productimage_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `main_image` text,
  `main_image_alt` varchar(255) NOT NULL DEFAULT '',
  `thumb_image` text,
  `thumb_image_alt` varchar(255) NOT NULL DEFAULT '',
  `tiny_image` text DEFAULT NULL,
  `tiny_image_alt` varchar(255) NOT NULL DEFAULT '',
  `additional_images` longtext,
  `additional_images_alt` longtext,
  `additional_thumb_images` longtext DEFAULT NULL,
  `additional_thumb_images_alt` longtext DEFAULT NULL,
  `additional_tiny_images` longtext DEFAULT NULL,
  `additional_tiny_images_alt` longtext DEFAULT NULL,
  PRIMARY KEY (`j2commerce_productimage_id`),
  KEY `productimage_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_options` (
  `j2commerce_option_id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL DEFAULT '',
  `option_unique_name` varchar(255) NOT NULL DEFAULT '',
  `option_name` varchar(255) NOT NULL DEFAULT '',
  `ordering` int NOT NULL DEFAULT 0,
  `enabled` tinyint NOT NULL DEFAULT 0,
  `option_params` text,
  `access` int UNSIGNED NOT NULL DEFAULT '0',
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int UNSIGNED NOT NULL DEFAULT '0',
  `modified_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` int UNSIGNED NOT NULL DEFAULT '0',
  `checked_out` int UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  PRIMARY KEY (`j2commerce_option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_optionvalues` (
  `j2commerce_optionvalue_id` int NOT NULL AUTO_INCREMENT,
  `option_id` int NOT NULL,
  `optionvalue_name` varchar(255) NOT NULL DEFAULT '',
  `optionvalue_image` longtext NOT NULL,
  `ordering` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`j2commerce_optionvalue_id`),
  KEY `option_id` (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_product_options` (
  `j2commerce_productoption_id` int NOT NULL AUTO_INCREMENT,
  `option_id` int NOT NULL,
  `parent_id` int NOT NULL DEFAULT 0,
  `product_id` int NOT NULL,
  `ordering` int NOT NULL DEFAULT 0,
  `required` int NOT NULL DEFAULT 0,
  `is_variant` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`j2commerce_productoption_id`),
  KEY `productoption_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_product_optionvalues` (
  `j2commerce_product_optionvalue_id` int NOT NULL AUTO_INCREMENT,
  `productoption_id` int NOT NULL,
  `optionvalue_id` int DEFAULT NULL,
  `parent_optionvalue` text NOT NULL,
  `product_optionvalue_price` decimal(15,8) NOT NULL DEFAULT 0.00000000,
  `product_optionvalue_prefix` varchar(255) NOT NULL DEFAULT '',
  `product_optionvalue_weight` decimal(15,8) NOT NULL DEFAULT 0.00000000,
  `product_optionvalue_weight_prefix` varchar(255) NOT NULL DEFAULT '',
  `product_optionvalue_sku` varchar(255) NOT NULL DEFAULT '',
  `product_optionvalue_default` int NOT NULL DEFAULT 0,
  `ordering` int NOT NULL DEFAULT 0,
  `product_optionvalue_attribs` text NOT NULL,
  PRIMARY KEY (`j2commerce_product_optionvalue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_filtergroups` (
  `j2commerce_filtergroup_id` int NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `ordering` int NOT NULL DEFAULT 0,
  `enabled` tinyint NOT NULL DEFAULT 0,
  `access` int UNSIGNED NOT NULL DEFAULT '0',
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int UNSIGNED NOT NULL DEFAULT '0',
  `modified_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` int UNSIGNED NOT NULL DEFAULT '0',
  `checked_out` int UNSIGNED DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  PRIMARY KEY (`j2commerce_filtergroup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_filters` (
  `j2commerce_filter_id` int NOT NULL AUTO_INCREMENT,
  `group_id` int NOT NULL,
  `filter_name` varchar(255) DEFAULT NULL,
  `ordering` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`j2commerce_filter_id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_product_filters` (
  `product_id` int NOT NULL,
  `filter_id` int NOT NULL,
  PRIMARY KEY (`product_id`,`filter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_productfiles` (
  `j2commerce_productfile_id` int NOT NULL AUTO_INCREMENT,
  `product_file_display_name` varchar(255) NOT NULL,
  `product_file_save_name` varchar(255) NOT NULL,
  `product_id` int NOT NULL,
  `download_total` int NOT NULL,
  PRIMARY KEY (`j2commerce_productfile_id`),
  KEY `productfile_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__j2commerce_metafields` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `metakey` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `scope` varchar(255) NOT NULL,
  `metavalue` text NOT NULL,
  `valuetype` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `owner_resource` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `metafields_owner_id_index` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
