<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

return [
    'com_appointments' => ['type' => 'component', 'element' => 'com_appointments', 'php' => ['sodium'], 'functions' => ['sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'sodium_crypto_box_seal'], 'mysql' => true, 'scheduler' => true, 'mail' => true],
    'com_dmarcreports' => ['type' => 'component', 'element' => 'com_dmarcreports', 'php' => ['simplexml', 'libxml', 'mbstring', 'openssl'], 'mysql' => true, 'optional' => ['zip', 'zlib']],
    'com_j2commerce_importexport' => ['type' => 'component', 'element' => 'com_j2commerce_importexport', 'shop' => true],
    'com_j2store_cleanup' => ['type' => 'component', 'element' => 'com_j2store_cleanup', 'shop' => true],
    'plg_ajax_joomlaajaxforms' => ['type' => 'plugin', 'element' => 'joomlaajaxforms', 'group' => 'ajax'],
    'plg_osmap_j2commerce' => ['type' => 'plugin', 'element' => 'j2commerce', 'group' => 'osmap', 'shop' => true, 'dependencies' => ['com_osmap'], 'routes' => [['com_osmap']]],
    'plg_privacy_j2commerce' => ['type' => 'plugin', 'element' => 'j2commerce', 'group' => 'privacy', 'shop' => true, 'dependencies' => ['com_privacy'], 'scheduler' => true, 'routes' => [
        ['com_ajax', 'plugin=j2commercePrivacy&group=privacy&format=html&task=confirmRequest'],
        ['com_ajax', 'plugin=j2commercePrivacy&group=privacy&format=json&task=deleteAddress'],
        ['com_privacy'],
    ]],
    'plg_j2commerce_productcompare' => ['type' => 'plugin', 'element' => 'productcompare', 'group' => 'j2commerce', 'shop' => true],
    'mod_dmarcreports' => ['type' => 'module', 'element' => 'mod_dmarcreports', 'dependencies' => ['com_dmarcreports'], 'routes' => [['com_dmarcreports']]],
    'plg_task_dmarcreports' => ['type' => 'plugin', 'element' => 'dmarcreports', 'group' => 'task', 'dependencies' => ['com_dmarcreports'], 'scheduler' => true, 'routes' => [['com_dmarcreports']]],
    'plg_system_dmarcmtasts' => ['type' => 'plugin', 'element' => 'dmarcmtasts', 'group' => 'system', 'dependencies' => ['com_dmarcreports'], 'routes' => [['com_dmarcreports']]],
    'plg_system_privacyrequests' => ['type' => 'plugin', 'element' => 'privacyrequests', 'group' => 'system', 'dependencies' => ['com_privacy'], 'php' => ['mbstring'], 'mail' => true, 'routes' => [
        ['com_ajax', 'plugin=privacyrequests&group=system&format=html'],
        ['com_ajax', 'plugin=privacyrequests&group=system&format=json&task=capabilities'],
    ]],
    'pkg_dmarcreports' => ['type' => 'package', 'element' => 'pkg_dmarcreports', 'dependencies' => ['com_dmarcreports', 'mod_dmarcreports', 'plg_task_dmarcreports', 'plg_system_dmarcmtasts'], 'routes' => [['com_dmarcreports']]],
    'plg_task_appointments' => ['type' => 'plugin', 'element' => 'appointments', 'group' => 'task', 'dependencies' => ['com_appointments'], 'scheduler' => true, 'routes' => [['com_appointments']]],
    'plg_privacy_appointments' => ['type' => 'plugin', 'element' => 'appointments', 'group' => 'privacy', 'dependencies' => ['com_appointments', 'com_privacy'], 'routes' => [['com_appointments']]],
    'plg_content_appointmentsprivacy' => ['type' => 'plugin', 'element' => 'appointmentsprivacy', 'group' => 'content', 'dependencies' => ['com_appointments', 'com_privacy'], 'routes' => [['com_privacy']]],
    'plg_system_appointmentsdav' => ['type' => 'plugin', 'element' => 'appointmentsdav', 'group' => 'system', 'dependencies' => ['com_appointments'], 'php' => ['sodium'], 'routes' => [['com_appointments']]],
    'plg_user_appointments' => ['type' => 'plugin', 'element' => 'appointments', 'group' => 'user', 'dependencies' => ['com_appointments'], 'routes' => [['com_appointments']]],
    'plg_task_j2commerceprivacy' => ['type' => 'plugin', 'element' => 'j2commerceprivacy', 'group' => 'task', 'shop' => true, 'scheduler' => true],
    'plg_system_j2commerceprivacy' => ['type' => 'plugin', 'element' => 'j2commerceprivacy', 'group' => 'system', 'shop' => true],
];
