<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 *
 * OSMap loads this file directly and expects the class PlgOsmapJ2commerce.
 * The actual implementation lives in src/Extension/J2Commerce.php.
 * J2CommerceNew handles com_j2commerce menu items (J2Commerce 4+).
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/src/Extension/J2Commerce.php';
require_once __DIR__ . '/src/Extension/J2CommerceNew.php';

use Advans\Plugin\Osmap\J2Commerce\Extension\J2Commerce;
use Advans\Plugin\Osmap\J2Commerce\Extension\J2CommerceNew;

// OSMap's legacy plugin loader finds the plugin class by the conventional name.
class_alias(J2Commerce::class, 'PlgOsmapJ2commerce');
