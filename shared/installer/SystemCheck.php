<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

final class SystemCheck
{
    public static function definition(string $id): array
    {
        $catalog = require __DIR__ . '/catalog.php';

        if (!isset($catalog[$id])) {
            throw new \InvalidArgumentException('Unknown extension check: ' . $id);
        }

        return $catalog[$id];
    }

    public static function loadLanguage(): void
    {
        Factory::getApplication()->getLanguage()->load('advans_installer', __DIR__);
    }

    public static function root(array $definition): string
    {
        switch ($definition['type']) {
            case 'component':
                return JPATH_ADMINISTRATOR . '/components/' . $definition['element'];
            case 'plugin':
                return JPATH_PLUGINS . '/' . $definition['group'] . '/' . $definition['element'];
            case 'module':
                return JPATH_ADMINISTRATOR . '/modules/' . $definition['element'];
            case 'package':
                return JPATH_ADMINISTRATOR . '/manifests/packages/' . substr($definition['element'], 4);
        }

        throw new \InvalidArgumentException('Unsupported extension type.');
    }

    /** @return array{passed: array, blockers: array, warnings: array} */
    public static function results(string $id): array
    {
        $definition = self::definition($id);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $results = ['passed' => [], 'blockers' => [], 'warnings' => []];
        $add = static function (bool $ok, string $label, string $actual, string $next = '', bool $optional = false) use (&$results): void {
            $results[$ok ? 'passed' : ($optional ? 'warnings' : 'blockers')][] = [$label, $actual, $next];
        };
        $add(version_compare(PHP_VERSION, '8.1', '>='), 'PHP >= 8.1', PHP_VERSION, Text::_('ADVANS_CHECK_SERVER'));
        $joomla = (new Version())->getShortVersion();
        $add(version_compare($joomla, '5.4', '>=') && version_compare($joomla, '7', '<'), 'Joomla 5.4 / 6', $joomla, Text::_('ADVANS_CHECK_JOOMLA_UPDATE'));
        $app = Factory::getApplication();
        $cli = $app instanceof \Joomla\CMS\Application\ConsoleApplication || $app->isClient('cli');
        $https = !$cli && Uri::getInstance()->getScheme() === 'https';
        $add($https, Text::_('ADVANS_CHECK_HTTPS'), Text::_($cli ? 'ADVANS_CHECK_HTTPS_UNKNOWN' : ($https ? 'ADVANS_CHECK_HTTPS_CURRENT' : 'ADVANS_CHECK_HTTPS_INSECURE')), Text::_('ADVANS_CHECK_HTTPS_PATH'), true);

        $htaccess = JPATH_ROOT . '/.htaccess';
        $routing = 'ADVANS_CHECK_ROUTING_ABSENT';

        if (is_file($htaccess)) {
            if (!is_readable($htaccess)) {
                $routing = 'ADVANS_CHECK_ROUTING_UNREADABLE';
            } else {
                $content = file_get_contents($htaccess);

                if ($content === false) {
                    throw new \RuntimeException('Cannot read the routing configuration.');
                }

                $routes = $definition['routes'] ?? [[$definition['type'] === 'component' ? $id : 'com_ajax']];
                $issues = [];

                foreach ($routes as $route) {
                    $issues = array_merge($issues, (new RoutingCheck())->analyse($content, $route[0], $route[1] ?? null));
                }
                $routing = $issues === [] ? 'ADVANS_CHECK_ROUTING_UNVERIFIED' : 'ADVANS_CHECK_ROUTING_BLOCKED';
            }
        }

        $results['warnings'][] = [Text::_('ADVANS_CHECK_ROUTING'), Text::_($routing), Text::_('ADVANS_CHECK_ROUTING_PATH')];

        foreach ($definition['php'] ?? [] as $extension) {
            $ok = extension_loaded($extension);
            $add($ok, 'PHP: ' . $extension, Text::_($ok ? 'ADVANS_CHECK_AVAILABLE' : 'ADVANS_CHECK_MISSING'), Text::_('ADVANS_CHECK_SERVER'));
        }

        foreach ($definition['functions'] ?? [] as $function) {
            $ok = function_exists($function);
            $add($ok, 'PHP: ' . $function, Text::_($ok ? 'ADVANS_CHECK_AVAILABLE' : 'ADVANS_CHECK_MISSING'), Text::_('ADVANS_CHECK_SERVER'));
        }

        foreach ($definition['optional'] ?? [] as $extension) {
            $ok = extension_loaded($extension);
            $add($ok, 'PHP: ' . $extension, Text::_($ok ? 'ADVANS_CHECK_AVAILABLE' : 'ADVANS_CHECK_MISSING'), Text::_('ADVANS_CHECK_OPTIONAL_ARCHIVES'), true);
        }

        if ($definition['mysql'] ?? false) {
            $server = $db->getServerType();
            $add($server === 'mysql', Text::_('ADVANS_CHECK_DATABASE'), $server, Text::_('ADVANS_CHECK_MYSQL'));
        }

        foreach ($definition['dependencies'] ?? [] as $dependency) {
            $found = self::extension($dependency);
            $ok = $found !== null && (int) $found->enabled === 1;
            $add($ok, $dependency, Text::_($ok ? 'ADVANS_CHECK_ENABLED' : 'ADVANS_CHECK_MISSING_DISABLED'), Text::_('ADVANS_CHECK_MANAGE'));
        }

        if ($definition['shop'] ?? false) {
            $tables = $db->getTableList();
            $shops = [];

            foreach (['com_j2commerce' => 'j2commerce', 'com_j2store' => 'j2store'] as $component => $prefix) {
                $shop = self::extension($component);

                if ($shop && (int) $shop->enabled === 1 && in_array($db->getPrefix() . $prefix . '_orders', $tables, true)) {
                    $shops[] = $component;
                }
            }

            $add($shops !== [], Text::_('ADVANS_CHECK_SHOP'), $shops !== [] ? implode(', ', $shops) : Text::_('ADVANS_CHECK_MISSING_DISABLED'), Text::_('ADVANS_CHECK_MANAGE'));
        }

        $installed = self::extension($id);

        if ($definition['type'] === 'plugin') {
            $enabled = $installed !== null && (int) $installed->enabled === 1;
            $add($enabled, Text::_('ADVANS_CHECK_PLUGIN'), Text::_($enabled ? 'ADVANS_CHECK_ENABLED' : 'ADVANS_CHECK_DISABLED'), Text::_('ADVANS_CHECK_PLUGINS'), true);
        }

        if ($definition['scheduler'] ?? false) {
            // A configured trigger cannot prove that a host cron actually runs.
            $results['warnings'][] = [Text::_('ADVANS_CHECK_SCHEDULER'), Text::_('ADVANS_CHECK_TRIGGER_UNVERIFIED'), Text::_('ADVANS_CHECK_SCHEDULER_PATH')];
        }

        if ($definition['mail'] ?? false) {
            $enabled = (bool) Factory::getApplication()->get('mailonline', true);
            $add($enabled, Text::_('ADVANS_CHECK_MAIL'), Text::_($enabled ? 'ADVANS_CHECK_MAIL_UNVERIFIED' : 'ADVANS_CHECK_DISABLED'), Text::_('ADVANS_CHECK_MAIL_PATH'), true);
        }

        $root = self::root($definition);
        $add(is_dir($root) && is_writable($root), Text::_('ADVANS_CHECK_FILES'), Text::_(is_dir($root) && is_writable($root) ? 'ADVANS_CHECK_WRITABLE' : 'ADVANS_CHECK_NOT_WRITABLE'), Text::_('ADVANS_CHECK_SERVER'));

        return $results;
    }

    public static function extension(string $id): ?object
    {
        $definition = in_array($id, ['com_j2commerce', 'com_j2store', 'com_privacy', 'com_osmap'], true)
            ? ['type' => 'component', 'element' => $id]
            : self::definition($id);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['extension_id', 'enabled']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($definition['type']))
            ->where($db->quoteName('element') . ' = ' . $db->quote($definition['element']));

        if (isset($definition['group'])) {
            $groups = [$definition['group']];

            if ($id === 'plg_j2commerce_productcompare' && Version::MAJOR_VERSION < 6) {
                $groups[] = 'j2store';
            }

            $query->whereIn($db->quoteName('folder'), $groups, \Joomla\Database\ParameterType::STRING);
        }

        return $db->setQuery($query)->loadObject() ?: null;
    }

    public static function url(string $id): string
    {
        $definition = self::definition($id);

        if ($definition['type'] === 'package') {
            return self::url('com_dmarcreports');
        }

        if ($definition['type'] === 'component') {
            return 'index.php?option=com_config&view=component&component=' . rawurlencode($id);
        }

        if ($definition['type'] === 'module') {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('id'))->from($db->quoteName('#__modules'))
                ->where($db->quoteName('module') . ' = ' . $db->quote($id))
                ->where($db->quoteName('client_id') . ' = 1')
                ->order($db->quoteName('id') . ' ASC')
                ->setLimit(1);
            $moduleId = (int) $db->setQuery($query)->loadResult();

            if ($moduleId > 0) {
                return 'index.php?option=com_modules&task=module.edit&client_id=1&id=' . $moduleId;
            }

            $extension = self::extension($id);

            return 'index.php?option=com_modules&task=module.add&client_id=1&eid=' . (int) ($extension->extension_id ?? 0);
        }

        $extension = self::extension($id);

        return $extension
            ? 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $extension->extension_id
            : 'index.php?option=com_plugins&view=plugins';
    }

    public static function render(string $id): string
    {
        self::loadLanguage();
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="advans-system-check" style="min-width:0;max-width:100%;overflow-wrap:anywhere"><h3>' . $escape(Text::_('ADVANS_CHECK_TITLE') . ' — ' . $id) . '</h3>';
        $html .= '<p>' . $escape(Text::_('ADVANS_CHECK_SCOPE')) . '</p>';

        $headings = ['passed' => 'ADVANS_CHECK_PASSED', 'blockers' => 'ADVANS_CHECK_BLOCKERS', 'warnings' => 'ADVANS_CHECK_WARNINGS'];

        foreach (self::results($id) as $group => $rows) {
            $html .= '<h4>' . $escape(Text::_($headings[$group])) . ' (' . count($rows) . ')</h4>';

            if ($rows === []) {
                $html .= '<p>' . $escape(Text::_('ADVANS_CHECK_NONE')) . '</p>';
                continue;
            }

            $html .= '<ul>';

            foreach ($rows as [$label, $actual, $next]) {
                $html .= '<li><strong>' . $escape($label) . '</strong>: ' . $escape($actual);
                $html .= $next !== '' ? ' — ' . $escape($next) : '';
                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '<p>' . $escape(Text::_('ADVANS_CHECK_REPEAT_PATH')) . '</p>';
        $html .= '<p>' . $escape(Text::_('ADVANS_CHECK_APPROVAL')) . '</p>';
        $html .= '<a class="btn btn-secondary" href="' . $escape(self::url($id)) . '">' . $escape(Text::_('ADVANS_CHECK_REPEAT')) . '</a>';

        return $html . '</section>';
    }
}
