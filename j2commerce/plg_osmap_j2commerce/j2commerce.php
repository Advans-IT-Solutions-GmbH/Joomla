<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 *
 * OSMap loads this file directly and expects the class PlgOsmapJ2commerce.
 * The actual implementation lives in src/Extension/J2Commerce.php.
 */

defined('_JEXEC') or die;

// Load the implementation class directly since OSMap bypasses Joomla's
// plugin loader and the PSR-4 autoloader may not have this namespace registered.
require_once __DIR__ . '/src/Extension/J2Commerce.php';

use Advans\Plugin\Osmap\J2Commerce\Extension\J2Commerce;

// Alias so OSMap's plugin loader finds the class by the conventional name.
class_alias(J2Commerce::class, 'PlgOsmapJ2commerce');
