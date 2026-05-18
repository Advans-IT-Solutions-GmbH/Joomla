<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

namespace Advans\Plugin\Osmap\J2Commerce\Extension;

defined('_JEXEC') or die;

/**
 * Handles com_j2commerce menu items (J2Commerce 4+).
 *
 * Identical logic to J2Commerce (J2Store), but targets com_j2commerce
 * and the #__j2commerce_products table.
 */
class J2CommerceNew extends J2Commerce
{
    protected string $component     = 'com_j2commerce';
    protected string $productsTable = '#__j2commerce_products';
}
