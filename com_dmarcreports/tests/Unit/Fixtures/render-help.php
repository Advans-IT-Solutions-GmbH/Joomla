<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Renders tmpl/help/default.php outside Joomla with the real strings of one
// language and prints the HTML. HelpPageTest runs this file in
// its own PHP process, so the Joomla stand-ins below never collide with the
// stubs other unit tests define.
// Usage: php render-help.php page <de-DE|en-GB|fr-FR> <1|0>   (1 = may open Options)

namespace Joomla\CMS\Language {
    final class Text
    {
        /** @var array<string, string> */
        public static array $strings = [];

        public static function _(string $key): string
        {
            return self::$strings[$key] ?? $key;
        }

        public static function sprintf(string $key, ...$args): string
        {
            return vsprintf(self::_($key), $args);
        }
    }
}

namespace Joomla\CMS\Router {
    final class Route
    {
        public static function _(string $url): string
        {
            return $url;
        }
    }
}

namespace {
    \define('_JEXEC', 1);

    $root = \dirname(__DIR__, 3) . '/administrator/components/com_dmarcreports';

    require_once $root . '/src/Helper/ActionPresenter.php';
    require_once $root . '/src/Helper/HelpTopics.php';

    $locale = $argv[2] ?? 'en-GB';
    $ini    = \dirname(__DIR__, 3) . "/administrator/language/{$locale}/com_dmarcreports.ini";

    \Joomla\CMS\Language\Text::$strings = parse_ini_file($ini, false, INI_SCANNER_RAW) ?: [];

    $view = new class {
        public bool $canOptions = true;
    };
    $view->canOptions = ($argv[3] ?? '1') === '1';

    $render = function () use ($root): void {
        include $root . '/tmpl/help/default.php';
    };

    \Closure::bind($render, $view, \get_class($view))();
}
