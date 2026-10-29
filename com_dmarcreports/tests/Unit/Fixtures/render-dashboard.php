<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Renders tmpl/dashboard/default.php outside Joomla and prints the HTML.
// DashboardLayoutTest runs this file in its own PHP process, so the minimal
// Joomla stand-ins below never collide with the stubs other unit tests define.
// Usage: php render-dashboard.php <1|0>   (1 = user may change the action status)

namespace Joomla\CMS {
    final class Factory
    {
        public static bool $canEditState = true;

        public static function getApplication(): object
        {
            return new class {
                public function getIdentity(): object
                {
                    return new class {
                        public function authorise(string $action, ?string $asset = null): bool
                        {
                            return Factory::$canEditState;
                        }
                    };
                }
            };
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }

        public static function sprintf(string $key, ...$args): string
        {
            return $key . ' ' . implode(' ', array_map('strval', $args));
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

namespace Joomla\CMS\Session {
    final class Session
    {
        public static function getFormToken(): string
        {
            return 'unittesttoken';
        }
    }
}

namespace {
    \define('_JEXEC', 1);

    $root = \dirname(__DIR__, 3) . '/administrator/components/com_dmarcreports';

    require_once $root . '/src/Helper/ActionPresenter.php';

    \Joomla\CMS\Factory::$canEditState = ($argv[1] ?? '1') === '1';

    $item = static function (string $code, int $severity, array $params, string $suffix = ''): array {
        return [
            'action_key' => $code . ':1' . ($suffix !== '' ? ':' . $suffix : ''),
            'code'       => $code,
            'category'   => 'policy',
            'severity'   => $severity,
            'domain_id'  => 1,
            'domain'     => 'example.org',
            'params'     => $params,
            'status'     => 'open',
        ];
    };

    $view = new class {
        public array $actions    = [];
        public array $suppressed = [];
        public array $totals     = [];
        public array $summaries  = [];
    };

    // A short and a very long text: before the fix the length of the text decided
    // whether a button stayed on the first line or wrapped to the next one.
    $view->actions = [
        $item('new_source', 2, ['192.0.2.1', 2], '192.0.2.1'),
        $item('at_target', 0, [str_repeat('example.org ', 40), 'none']),
    ];
    $view->suppressed = [
        array_merge($item('align_source', 2, ['192.0.2.2', 1, 1.0], '192.0.2.2'), ['status' => 'done']),
    ];
    $view->totals    = ['domains' => 1, 'total' => 3, 'pass_rate' => 100, 'reject_under_p' => 0];
    $view->summaries = [];

    $render = function () use ($root): void {
        include $root . '/tmpl/dashboard/default.php';
    };

    \Closure::bind($render, $view, \get_class($view))();
}
